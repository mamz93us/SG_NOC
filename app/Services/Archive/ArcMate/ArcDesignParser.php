<?php

namespace App\Services\Archive\ArcMate;

/**
 * Reads what ArcMate writes next to its own files, so an archive can describe
 * itself instead of being typed in by hand.
 *
 * Three files per project folder on the share:
 *
 *   project.inf            two lines: a version banner, then the display name
 *                          ("ArcMate Inf File ver 7\nSPS INVOICES")
 *   project.config         XML: which SQL database holds the index, and whether
 *                          the files were written encrypted
 *   Documents/arcDesign.xml  XML: one <Field> per searchable field, whose
 *                          `DBName` (S1, S2, D1, C1 …) is the column the value
 *                          sits in on tblDocuments
 *
 * Every method takes CONTENTS rather than a path, so the whole thing is a pure
 * function and can be tested against real fixtures without a mounted share.
 *
 * project.config also carries ArcMate's `sa` login and an obfuscated password.
 * They are deliberately not read, not returned and not logged: the NOC connects
 * with its own read-only login, and copying that secret into another system
 * would only widen the exposure that already exists on the share.
 */
class ArcDesignParser
{
    /**
     * ArcMate's field type numbers, as seen in the live arcDesign.xml files.
     *
     * 1 is text (Supplier Name, InvoiceNo — and amounts too: ArcMate keeps
     * "Invoice Amount" as a 20-character string, which is why a number here is
     * a presentation choice on our side rather than a fact on theirs).
     * 3 is a date, always paired with a D-column. 5 is a fixed list, paired
     * with a C-column.
     */
    private const TYPE_MAP = [
        1 => 'text',
        3 => 'date',
        5 => 'list',
    ];

    /**
     * The display name from project.inf — the second line.
     *
     * The first line is a version banner ("ArcMate Inf File ver 7"), identical
     * in every project, so it is skipped rather than matched: a version 8 would
     * otherwise read as the archive's name.
     */
    public static function name(string $contents): ?string
    {
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach (array_slice($lines, 1) as $line) {
            $name = trim($line);
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * The parts of project.config the NOC is allowed to care about.
     *
     * @return array{database:?string, encrypted:bool, login_page:?string, project_id:?string}
     */
    public static function config(string $xml): array
    {
        $root = self::xml($xml);

        return [
            'database' => self::text($root, 'Db_Name'),
            // <Encrypt>1</Encrypt> means the stored bytes are ciphertext that
            // only ArcMate can undo. Of the twelve live projects exactly one —
            // the throwaway "Test" — has it set.
            'encrypted' => self::text($root, 'Encrypt') === '1',
            'login_page' => self::text($root, 'Project_Login'),
            'project_id' => self::text($root, 'Project_ID'),
        ];
    }

    /**
     * The searchable fields from Documents/arcDesign.xml, in file order.
     *
     * Keys are derived from the caption and are what URLs and search forms use;
     * ArcMate has no such notion, so they are generated here: lower-cased,
     * non-alphanumerics collapsed to underscores. A collision (two fields whose
     * captions reduce to the same word) is broken by appending the ArcMate
     * column, which is unique within a project by definition.
     *
     * @return array<int,array{key:string,label:string,type:string,required:bool,is_unique:bool,arcmate_column:?string,arcmate_type:?int,max_length:?int,options:array<int,string>}>
     */
    public static function fields(string $xml): array
    {
        $root = self::xml($xml);

        if (! $root) {
            return [];
        }

        $fields = [];
        $used = [];

        foreach ($root->Field as $node) {
            $label = trim((string) ($node->Caption ?? '')) ?: trim((string) ($node->Name ?? ''));
            $column = trim((string) ($node->DBName ?? ''));

            if ($label === '' && $column === '') {
                continue;
            }

            $rawType = trim((string) ($node->Type ?? ''));
            $type = self::TYPE_MAP[(int) $rawType] ?? 'text';

            $key = self::key($label !== '' ? $label : $column);

            if ($key === '' || isset($used[$key])) {
                $key = trim($key.'_'.strtolower($column), '_');
            }

            // Still colliding (or still empty) means the project has two fields
            // with the same caption AND the same column, which cannot happen in
            // ArcMate — but a generated key must be unique regardless.
            if ($key === '' || isset($used[$key])) {
                $key = 'field_'.(count($fields) + 1);
            }

            $used[$key] = true;

            $size = (int) trim((string) ($node->Size ?? ''));

            $fields[] = [
                'key' => $key,
                'label' => $label !== '' ? $label : $column,
                'type' => $type,
                'required' => self::bool($node->Required ?? null),
                'is_unique' => self::bool($node->Unique ?? null)
                    || self::bool($node->UniquePerApplication ?? null),
                'arcmate_column' => $column !== '' ? $column : null,
                'arcmate_type' => $rawType === '' ? null : (int) $rawType,
                // Dates and lists carry Size 0; only a text length is real.
                'max_length' => ($type === 'text' && $size > 0) ? $size : null,
                'options' => self::options($node),
            ];
        }

        return $fields;
    }

    /**
     * A URL- and form-safe key from a caption: "Supplier Name" → supplier_name,
     * "Service Req. Date" → service_req_date.
     */
    public static function key(string $label): string
    {
        $key = strtolower(trim($label));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';

        return trim($key, '_');
    }

    /** @return array<int,string> The fixed choices of a list field. */
    private static function options(\SimpleXMLElement $node): array
    {
        $values = $node->Values ?? null;

        if ($values === null) {
            return [];
        }

        $options = [];

        // ArcMate writes an empty <Values /> for a list whose choices were
        // never filled in, and otherwise a child element per choice.
        foreach ($values->children() as $child) {
            $option = trim((string) $child);
            if ($option !== '') {
                $options[] = $option;
            }
        }

        if ($options === []) {
            foreach (preg_split('/\R/', trim((string) $values)) ?: [] as $line) {
                $option = trim($line);
                if ($option !== '') {
                    $options[] = $option;
                }
            }
        }

        return array_values(array_unique($options));
    }

    private static function bool(mixed $node): bool
    {
        return strcasecmp(trim((string) $node), 'true') === 0 || trim((string) $node) === '1';
    }

    private static function text(?\SimpleXMLElement $root, string $element): ?string
    {
        if (! $root) {
            return null;
        }

        $value = trim((string) ($root->{$element} ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * Parse without letting a malformed file raise a PHP warning or an
     * exception: these files come off a share written by a 2005 application,
     * and one bad project must not stop the other eleven being read.
     */
    private static function xml(string $xml): ?\SimpleXMLElement
    {
        $xml = trim($xml);

        if ($xml === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $root = simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $root instanceof \SimpleXMLElement ? $root : null;
    }
}
