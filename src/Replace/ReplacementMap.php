<?php

namespace SiteShifter\Replace;

/**
 * Builds the full list of "search => replace" pairs for a migration.
 *
 * A single old URL appears in a WordPress database in many spellings:
 * http and https, with and without "www", protocol-relative (//host),
 * JSON-escaped (https:\/\/host, used by Elementor and the block editor)
 * and URL-encoded (https%3A%2F%2Fhost, used in query strings and some
 * plugin settings). Every spelling is mapped to the matching spelling of
 * the new URL so the replacement keeps each value's format intact.
 */
class ReplacementMap
{
    /** @var array<string,string> search => replace */
    private $pairs = [];

    /** @var array<string,bool> searches that must not be followed by a host character */
    private $hostBoundary = [];

    /**
     * Adds a site URL move, e.g. http://old.test => https://new.com.
     *
     * @param bool $includeWww Also map www.<host> (or the bare host when the old URL has www).
     */
    public function addUrl(string $oldUrl, string $newUrl, bool $includeWww = true): self
    {
        $old = self::parseUrl($oldUrl);
        $new = self::parseUrl($newUrl);

        $newFull = $new['scheme'] . '://' . $new['rest'];
        $newRelative = '//' . $new['rest'];

        $oldRests = [$old['rest']];
        if ($includeWww) {
            if (strpos($old['rest'], 'www.') === 0) {
                $oldRests[] = substr($old['rest'], 4);
            } elseif (!self::isIpOrLocal($old['host'])) {
                $oldRests[] = 'www.' . $old['rest'];
            }
        }

        foreach ($oldRests as $rest) {
            foreach (['https://', 'http://'] as $scheme) {
                $this->addVariants($scheme . $rest, $newFull, true);
            }
            $this->addVariants('//' . $rest, $newRelative, true);
        }

        return $this;
    }

    /**
     * Adds a server path move, e.g. /home/u1/public_html => /home/u2/domains/new.com/public_html.
     */
    public function addPath(string $oldPath, string $newPath): self
    {
        $oldPath = rtrim(str_replace('\\', '/', $oldPath), '/');
        $newPath = rtrim(str_replace('\\', '/', $newPath), '/');
        if ($oldPath === '' || $oldPath === $newPath) {
            return $this;
        }
        $this->addVariants($oldPath, $newPath, false);

        return $this;
    }

    /**
     * Adds a literal pair with no variants (for advanced use, e.g. an email domain).
     */
    public function addLiteral(string $search, string $replace): self
    {
        if ($search !== '' && $search !== $replace) {
            $this->pairs[$search] = $replace;
        }

        return $this;
    }

    /** @return array<string,string> */
    public function pairs(): array
    {
        return $this->pairs;
    }

    public function needsHostBoundary(string $search): bool
    {
        return isset($this->hostBoundary[$search]);
    }

    public function isEmpty(): bool
    {
        return $this->pairs === [];
    }

    /**
     * Distinct strings that identify the old site; used by the verification scan.
     *
     * @return string[]
     */
    public function searches(): array
    {
        return array_keys($this->pairs);
    }

    private function addVariants(string $search, string $replace, bool $hostBoundary): void
    {
        if ($search === $replace) {
            return;
        }
        $variants = [
            [$search, $replace],
            [str_replace('/', '\\/', $search), str_replace('/', '\\/', $replace)],
            [self::urlEncode($search), self::urlEncode($replace)],
        ];
        foreach ($variants as [$s, $r]) {
            if ($s === $r || isset($this->pairs[$s])) {
                continue;
            }
            $this->pairs[$s] = $r;
            if ($hostBoundary) {
                $this->hostBoundary[$s] = true;
            }
        }
    }

    private static function urlEncode(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * @return array{scheme:string,host:string,rest:string}
     */
    private static function parseUrl(string $url): array
    {
        $url = trim($url);
        if (!preg_match('#^(https?)://([^/?\#]+)(/[^?\#]*)?#i', $url, $m)) {
            throw new \InvalidArgumentException("Not a valid site URL: {$url}");
        }
        $scheme = strtolower($m[1]);
        $host = strtolower($m[2]);
        $path = isset($m[3]) ? rtrim($m[3], '/') : '';

        return ['scheme' => $scheme, 'host' => $host, 'rest' => $host . $path];
    }

    private static function isIpOrLocal(string $host): bool
    {
        $bare = preg_replace('/:\d+$/', '', $host);

        return $bare === 'localhost'
            || filter_var($bare, FILTER_VALIDATE_IP) !== false
            || strpos($bare, '.') === false;
    }
}
