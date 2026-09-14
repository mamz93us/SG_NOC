<?php

namespace App\Services\Ai\Web;

/**
 * robots.txt, read the way crawlers agree on: the group naming our agent if
 * there is one, else the "*" group; the longest matching rule decides, an
 * Allow winning a tie; "*" matches anything and "$" ends the path.
 */
class RobotsTxt
{
    /** @param  array<int, array{allow: bool, pattern: string}>  $rules */
    private function __construct(private array $rules) {}

    public static function allowAll(): self
    {
        return new self([]);
    }

    public static function parse(string $body, string $agent): self
    {
        $groups = [];
        $agents = [];
        $readingRules = false;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if (! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                // A User-agent line after rules starts a new group.
                if ($readingRules) {
                    $agents = [];
                    $readingRules = false;
                }
                $agents[] = strtolower($value);

                continue;
            }

            if ($field === 'allow' || $field === 'disallow') {
                $readingRules = true;

                if ($value === '') {
                    continue; // "Disallow:" with nothing after it allows everything
                }

                foreach ($agents as $name) {
                    $groups[$name][] = ['allow' => $field === 'allow', 'pattern' => $value];
                }
            }
        }

        $agent = strtolower($agent);

        foreach ($groups as $name => $rules) {
            if ($name !== '*' && $name !== '' && str_contains($agent, $name)) {
                return new self($rules);
            }
        }

        return new self($groups['*'] ?? []);
    }

    /** $path is the URL's path with its query string, e.g. "/hr/leave?print=1". */
    public function allows(string $path): bool
    {
        $decision = null;

        foreach ($this->rules as $rule) {
            if (! self::matches($rule['pattern'], $path)) {
                continue;
            }

            $length = strlen($rule['pattern']);

            if ($decision === null || $length > $decision['length'] || ($length === $decision['length'] && $rule['allow'])) {
                $decision = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $decision === null || $decision['allow'];
    }

    private static function matches(string $pattern, string $path): bool
    {
        $regex = '#^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '#')).'#';

        return (bool) preg_match($regex, $path);
    }
}
