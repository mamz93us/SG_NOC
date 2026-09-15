<?php

namespace App\Services\Archive\ArcMate;

use App\Models\Archive\ArchiveSource;

/**
 * Reads the ArcMate share and reports what is on it, so an archive describes
 * itself instead of being typed in by hand.
 *
 * Every ArcMate project is a folder holding `project.inf` (its display name),
 * `project.config` (which SQL database holds the index, and whether the files
 * were written encrypted) and `Documents/arcDesign.xml` (its searchable
 * fields). Twelve of them exist; reading them is how the portal learns that
 * SPS Invoices searches on an invoice number in column S1.
 *
 * This only ever REPORTS. Nothing is created here — an administrator reviews
 * the proposals and decides which archives to enable, because enabling one is
 * also deciding it is worth mirroring half a million documents.
 *
 * A folder without a project.config is not a project: the share's root
 * `Documents` folder holds stray images and no project files at all, and must
 * not be offered as an archive.
 */
class ArcMateDiscovery
{
    public function __construct(private ArchiveSource $source) {}

    /**
     * Whether the share is actually mounted and readable.
     *
     * Its own question because the answer is usually a system problem — the
     * cifs mount is missing or the credentials expired — and an empty archive
     * list would report that as "ArcMate has no projects".
     */
    public function mountAvailable(): bool
    {
        $path = $this->source->mountPath();

        return $path !== '' && @is_dir($path);
    }

    /**
     * Every project folder on the share, in name order.
     *
     * @return array<int,array{
     *     folder:string, name:?string, database:?string, encrypted:bool,
     *     fields:array<int,array<string,mixed>>, has_documents:bool, issue:?string
     * }>
     */
    public function scan(): array
    {
        if (! $this->mountAvailable()) {
            return [];
        }

        $projects = [];

        foreach ($this->folders() as $folder) {
            $project = $this->project($folder);

            if ($project !== null) {
                $projects[] = $project;
            }
        }

        usort($projects, fn (array $a, array $b) => strcasecmp((string) ($a['name'] ?: $a['folder']), (string) ($b['name'] ?: $b['folder'])));

        return $projects;
    }

    /**
     * One project folder, or null when it is not a project.
     *
     * @return array{folder:string, name:?string, database:?string, encrypted:bool, fields:array<int,array<string,mixed>>, has_documents:bool, issue:?string}|null
     */
    public function project(string $folder): ?array
    {
        $base = $this->source->mountPath().'/'.trim($folder, '/\\');

        $configXml = $this->read($base.'/project.config');

        // No project.config means this folder is not an ArcMate project — the
        // share's root `Documents` folder is exactly that case.
        if ($configXml === null) {
            return null;
        }

        $config = ArcDesignParser::config($configXml);
        $name = ArcDesignParser::name((string) $this->read($base.'/project.inf'));
        $designXml = $this->read($base.'/Documents/arcDesign.xml');
        $fields = $designXml === null ? [] : ArcDesignParser::fields($designXml);

        return [
            'folder' => trim($folder, '/\\'),
            'name' => $name,
            'database' => $config['database'],
            'encrypted' => $config['encrypted'],
            'fields' => $fields,
            'has_documents' => @is_dir($base.'/Documents'),
            'issue' => $this->issue($config, $fields, $designXml !== null),
        ];
    }

    /**
     * The one thing worth saying about a project before anyone enables it.
     *
     * @param  array{database:?string, encrypted:bool}  $config
     * @param  array<int,array<string,mixed>>  $fields
     */
    private function issue(array $config, array $fields, bool $hasDesign): ?string
    {
        if ($config['encrypted']) {
            return 'ArcMate stored this project encrypted, so its files cannot be opened outside ArcMate. '
                .'It can be listed but not mirrored — export anything needed through ArcMate before the server is switched off.';
        }

        if (! $config['database']) {
            return 'project.config names no database, so there is no index to read.';
        }

        if (! $hasDesign) {
            return 'No Documents/arcDesign.xml, so the searchable fields are unknown.';
        }

        if ($fields === []) {
            return 'arcDesign.xml defines no fields — documents would have nothing to search on.';
        }

        return null;
    }

    /** @return array<int,string> */
    private function folders(): array
    {
        $entries = @scandir($this->source->mountPath());

        if ($entries === false) {
            return [];
        }

        $folders = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (@is_dir($this->source->mountPath().'/'.$entry)) {
                $folders[] = $entry;
            }
        }

        return $folders;
    }

    /**
     * Read one small file off the share, or null.
     *
     * Silenced deliberately: the share is a remote mount that can disappear
     * mid-scan, and one unreadable project must not stop the other eleven being
     * reported.
     */
    private function read(string $path): ?string
    {
        if (! @is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}
