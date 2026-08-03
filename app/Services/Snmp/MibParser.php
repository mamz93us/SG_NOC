<?php

namespace App\Services\Snmp;

use Illuminate\Support\Facades\Storage;

class MibParser
{
    /** Standard SMI roots every MIB tree ultimately hangs off. */
    private const ROOTS = [
        'iso' => '1',
        'org' => '1.3',
        'dod' => '1.3.6',
        'internet' => '1.3.6.1',
        'directory' => '1.3.6.1.1',
        'mgmt' => '1.3.6.1.2',
        'mib-2' => '1.3.6.1.2.1',
        'transmission' => '1.3.6.1.2.1.10',
        'experimental' => '1.3.6.1.3',
        'private' => '1.3.6.1.4',
        'enterprises' => '1.3.6.1.4.1',
        'snmpV2' => '1.3.6.1.6',
    ];

    /** Definition keywords that assign a node an OID. */
    private const KEYWORDS = 'OBJECT IDENTIFIER|OBJECT-TYPE|MODULE-IDENTITY|NOTIFICATION-TYPE|OBJECT-IDENTITY|OBJECT-GROUP|NOTIFICATION-GROUP|MODULE-COMPLIANCE';

    /** Guard against malformed MIBs producing a cyclic parent chain. */
    private const MAX_DEPTH = 64;

    /** Per-request cache of parsed symbol tables, keyed by storage path. */
    private array $cache = [];

    /**
     * Parse a MIB file and extract OBJECT-TYPE definitions.
     *
     * @param  string  $filePath  Relative path to the MIB file in local storage.
     * @return array List of discovered OIDs and their names.
     */
    public function parseObjects(string $filePath): array
    {
        if (! Storage::disk('local')->exists($filePath)) {
            return [];
        }

        $table = $this->symbolTable($filePath);

        // A MIB usually IMPORTS its own root from a sibling module — FORTINET-FORTIGATE-MIB
        // hangs off `fortinet`, which is only defined in FORTINET-CORE-MIB. Without the
        // sibling nodes every OID here would fall back to an unusable textual name, so
        // merge in every other MIB in the same folder. The target file's own definitions win.
        $nodes = $this->siblingNodes($filePath) + $table['nodes'];

        $objects = [];

        foreach ($table['objects'] as $name => $meta) {
            $oid = $this->resolve($name, $nodes);

            $objects[] = [
                'name' => $name,
                // Absolute numeric OID where resolvable, otherwise a textual OID that
                // only works if net-snmp has the module compiled into its MIB path.
                'oid_suffix' => $oid !== null ? '.'.$oid : $table['module'].$name,
                'parent' => $nodes[$name][0] ?? 'unknown',
                'syntax' => $meta['syntax'],
                'units' => $meta['units'],
                'full_definition' => $meta['definition'],
            ];
        }

        return $objects;
    }

    /**
     * Resolve a symbolic node name to a numeric OID by walking up to a known SMI root.
     * Returns null when the chain dead-ends, cycles, or runs too deep.
     */
    private function resolve(string $name, array $nodes): ?string
    {
        $chain = [];
        $seen = [];
        $current = $name;

        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            if (isset(self::ROOTS[$current])) {
                array_unshift($chain, self::ROOTS[$current]);

                return implode('.', $chain);
            }

            // Malformed MIBs can bind a node to itself (or to a loop). Bail instead of
            // spinning forever — this parser runs inside a web request.
            if (isset($seen[$current]) || ! isset($nodes[$current])) {
                return null;
            }

            $seen[$current] = true;
            array_unshift($chain, $nodes[$current][1]);
            $current = $nodes[$current][0];
        }

        return null;
    }

    /**
     * Node definitions from every *other* MIB file sitting alongside this one.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function siblingNodes(string $filePath): array
    {
        $nodes = [];

        foreach (Storage::disk('local')->files(dirname($filePath)) as $sibling) {
            if ($sibling === $filePath || ! preg_match('/\.(mib|txt)$/i', $sibling)) {
                continue;
            }

            $nodes += $this->symbolTable($sibling)['nodes'];
        }

        return $nodes;
    }

    /**
     * Build the symbol table for one MIB file.
     *
     * Parsed line-by-line rather than with a whole-file regex: MIB files run to
     * hundreds of KB, and the assignment syntax varies enough ("::= { fgSystem 4 }",
     * "::= {fgSystem 11}", or the "::=" wrapped onto its own line) that a single
     * pattern over the full text mis-binds nodes to the wrong parent.
     *
     * @return array{module: string, nodes: array<string, array{0: string, 1: string}>, objects: array<string, array{syntax: ?string, units: ?string, definition: string}>}
     */
    private function symbolTable(string $filePath): array
    {
        if (isset($this->cache[$filePath])) {
            return $this->cache[$filePath];
        }

        $content = Storage::disk('local')->get($filePath) ?? '';
        $lines = preg_split('/\R/', $content) ?: [];
        $count = count($lines);

        $module = '';
        if (preg_match('/^\s*([A-Za-z0-9\-]+)\s+DEFINITIONS\s*::=\s*BEGIN/m', $content, $m)) {
            $module = $m[1].'::';
        }

        $nodes = [];
        $objects = [];

        for ($i = 0; $i < $count; $i++) {
            if (! preg_match('/^([a-zA-Z][a-zA-Z0-9\-]*)\s+('.self::KEYWORDS.')(\s|$)/', $lines[$i], $header)) {
                continue;
            }

            $name = $header[1];
            $keyword = $header[2];
            $definition = '';

            // Walk forward to the "::= { parent index }" that closes this definition.
            // The bound keeps a malformed file from scanning to EOF for every symbol.
            for ($j = $i; $j < min($count, $i + 400); $j++) {
                $definition .= $lines[$j]."\n";

                if (preg_match('/::=\s*\{\s*([a-zA-Z][a-zA-Z0-9\-]*)\s+(\d+)\s*\}/', $lines[$j], $assign)) {
                    $nodes[$name] = [$assign[1], $assign[2]];
                    break;
                }

                // "::= {" with the parent on the following line.
                if (preg_match('/::=\s*\{\s*$/', $lines[$j])
                    && isset($lines[$j + 1])
                    && preg_match('/^\s*([a-zA-Z][a-zA-Z0-9\-]*)\s+(\d+)\s*\}/', $lines[$j + 1], $assign)) {
                    $nodes[$name] = [$assign[1], $assign[2]];
                    $definition .= $lines[$j + 1]."\n";
                    break;
                }

                // Reached the next definition without an assignment — give up on this one.
                if ($j > $i && preg_match('/^[a-zA-Z][a-zA-Z0-9\-]*\s+('.self::KEYWORDS.')(\s|$)/', $lines[$j])) {
                    break;
                }
            }

            if ($keyword !== 'OBJECT-TYPE') {
                continue;
            }

            preg_match('/SYNTAX\s+(.+)/', $definition, $syntax);
            preg_match('/UNITS\s+"([^"]+)"/', $definition, $units);

            $objects[$name] = [
                'syntax' => isset($syntax[1]) ? trim($syntax[1]) : null,
                'units' => $units[1] ?? null,
                'definition' => trim($definition),
            ];
        }

        return $this->cache[$filePath] = [
            'module' => $module,
            'nodes' => $nodes,
            'objects' => $objects,
        ];
    }
}
