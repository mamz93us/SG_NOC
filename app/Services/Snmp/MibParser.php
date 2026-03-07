<?php

namespace App\Services\Snmp;

use Illuminate\Support\Facades\Storage;

class MibParser
{
    /**
     * Parse a MIB file and extract OBJECT-TYPE definitions.
     * 
     * @param string $filePath Relative path to the MIB file in local storage.
     * @return array List of discovered OIDs and their names.
     */
    public function parseObjects(string $filePath): array
    {
        if (!Storage::disk('local')->exists($filePath)) {
            return [];
        }

        $content = Storage::disk('local')->get($filePath);
        $objects = [];

        $moduleName = '';
        if (preg_match('/^([a-zA-Z0-9\-]+)\s+DEFINITIONS\s*::=\s*BEGIN/m', $content, $modMatch)) {
            $moduleName = $modMatch[1] . '::';
        }

        $nodes = [
            'iso' => '.1',
            'org' => '.1.3',
            'dod' => '.1.3.6',
            'internet' => '.1.3.6.1',
            'directory' => '.1.3.6.1.1',
            'mgmt' => '.1.3.6.1.2',
            'mib-2' => '.1.3.6.1.2.1',
            'experimental' => '.1.3.6.1.3',
            'private' => '.1.3.6.1.4',
            'enterprises' => '.1.3.6.1.4.1',
        ];

        // Pass 1: Map all OIDs in the MIB (IDENTIFIER, TYPE, IDENTITY)
        $pattern = '/([a-zA-Z0-9\-]+)\s+(?:OBJECT IDENTIFIER|OBJECT-TYPE|MODULE-IDENTITY|NOTIFICATION-TYPE)\s+.*?::=\s*\{\s*([a-zA-Z0-9\-\s]+)\s+\}/s';
        $rawMap = [];
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = $match[1];
                $parts = preg_split('/\s+/', trim($match[2]));
                $parent = $parts[0] ?? '';
                $index = end($parts);
                if (is_numeric($index)) {
                    $rawMap[$name] = ['parent' => $parent, 'index' => $index];
                }
            }
        }

        // Pass 2: Extract just the OBJECT-TYPE sensors and build their absolute OIDs
        $objPattern = '/([a-zA-Z0-9\-]+)\s+OBJECT-TYPE\s+SYNTAX\s+([a-zA-Z0-9\s\{\}]+?)(?:\s+UNITS\s+"([^"]+)")?\s+.*?::=\s*\{\s*([a-zA-Z0-9\-\s]+)\s+\}/s';
        if (preg_match_all($objPattern, $content, $objMatches, PREG_SET_ORDER)) {
            foreach ($objMatches as $match) {
                $name = $match[1];
                $syntax = trim($match[2]);
                $units = $match[3] ?? null;
                
                $currentName = $name;
                $oidChain = [];
                $failed = false;
                
                while(true) {
                    if (isset($nodes[$currentName])) {
                        array_unshift($oidChain, ltrim($nodes[$currentName], '.'));
                        break;
                    }
                    if (isset($rawMap[$currentName])) {
                        array_unshift($oidChain, $rawMap[$currentName]['index']);
                        $currentName = $rawMap[$currentName]['parent'];
                    } else {
                        // Dead end (parent not defined in this file and not a standard root)
                        $failed = true;
                        break;
                    }
                }
                
                if (!$failed) {
                    $absoluteOid = '.' . implode('.', $oidChain);
                    $objects[] = [
                        'name' => $name,
                        'oid_suffix' => $absoluteOid, // Guaranteed absolute numeric OID!
                        'parent' => $rawMap[$name]['parent'] ?? 'unknown',
                        'syntax' => $syntax,
                        'units' => $units,
                        'full_definition' => $match[0]
                    ];
                } else {
                    // Fallback to textual OID if we couldn't resolve the tree
                    $objects[] = [
                        'name' => $name,
                        'oid_suffix' => $moduleName . $name,
                        'parent' => $rawMap[$name]['parent'] ?? 'unknown',
                        'syntax' => $syntax,
                        'units' => $units,
                        'full_definition' => $match[0]
                    ];
                }
            }
        }

        return $objects;
    }
}
