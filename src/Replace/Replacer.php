<?php

namespace SiteShifter\Replace;

/**
 * Replaces old-site strings with new-site strings without breaking
 * PHP-serialized data.
 *
 * Serialized values are rewritten by a small parser instead of
 * unserialize()/serialize(), so objects are never instantiated (no
 * __wakeup side effects, no missing-class problems) and every string
 * length prefix is recalculated after replacement. Strings that are
 * themselves serialized (double serialization, common in widgets and
 * some plugins) are handled recursively.
 *
 * All pairs are applied in a single pass, so a new URL that contains the
 * old one (old.com => staging.old.com) is never replaced twice.
 */
class Replacer
{
    /** @var ReplacementMap */
    private $map;

    /** @var string|null */
    private $regex;

    /** @var array<string,string> */
    private $pairs;

    /** @var int */
    private $maxDepth;

    public function __construct(ReplacementMap $map, int $maxDepth = 8)
    {
        $this->map = $map;
        $this->pairs = $map->pairs();
        $this->maxDepth = $maxDepth;
        $this->regex = $this->buildRegex();
    }

    /**
     * Replaces in any database value. Returns the value unchanged when it
     * contains none of the search strings.
     */
    public function replace(string $value): string
    {
        return $this->replaceValue($value, 0);
    }

    /** Fast check used to skip rows that need no work. */
    public function matches(string $value): bool
    {
        return $this->regex !== null && preg_match($this->regex, $value) === 1;
    }

    private function replaceValue(string $value, int $depth): string
    {
        if (!$this->matches($value)) {
            return $value;
        }
        if ($depth < $this->maxDepth && self::looksSerialized($value)) {
            $parsed = (new SerializedRewriter(function (string $s) use ($depth): string {
                return $this->replaceValue($s, $depth + 1);
            }))->rewrite($value);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return $this->replacePlain($value);
    }

    private function replacePlain(string $value): string
    {
        $pairs = $this->pairs;
        $result = preg_replace_callback($this->regex, static function (array $m) use ($pairs): string {
            return $pairs[$m[0]];
        }, $value);

        return $result === null ? $value : $result;
    }

    private static function looksSerialized(string $value): bool
    {
        return strlen($value) >= 4
            && $value[1] === ':'
            && strpos('aOsCE', $value[0]) !== false;
    }

    private function buildRegex(): ?string
    {
        if ($this->pairs === []) {
            return null;
        }
        $searches = array_keys($this->pairs);
        usort($searches, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });
        $parts = [];
        foreach ($searches as $search) {
            $quoted = preg_quote($search, '~');
            if ($this->map->needsHostBoundary($search)) {
                // Stop "old.com" from matching "old.com.tr" or "old.company".
                $quoted .= '(?![A-Za-z0-9_-]|\.[A-Za-z0-9])';
            }
            $parts[] = $quoted;
        }

        return '~' . implode('|', $parts) . '~';
    }
}
