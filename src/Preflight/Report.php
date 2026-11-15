<?php

namespace SiteShifter\Preflight;

/** The full pre-flight report. A report with any BLOCK result must not start a migration. */
class Report
{
    /** @var Result[] */
    private $results = [];

    public function add(Result $result): void
    {
        $this->results[] = $result;
    }

    public function merge(Report $other): void
    {
        foreach ($other->all() as $r) {
            $this->add($r);
        }
    }

    /** @return Result[] */
    public function all(): array
    {
        return $this->results;
    }

    /** @return Result[] */
    public function byLevel(string $level): array
    {
        return array_values(array_filter($this->results, static function (Result $r) use ($level) {
            return $r->level === $level;
        }));
    }

    public function get(string $id): ?Result
    {
        foreach ($this->results as $r) {
            if ($r->id === $id) {
                return $r;
            }
        }

        return null;
    }

    public function hasBlockers(): bool
    {
        return $this->byLevel(Result::BLOCK) !== [];
    }

    public function toArray(): array
    {
        return array_map(static function (Result $r) {
            return $r->toArray();
        }, $this->results);
    }

    /** Plain-text rendering for the CLI. */
    public function toText(): string
    {
        $icons = [Result::OK => '[ OK  ]', Result::WARN => '[WARN ]', Result::BLOCK => '[BLOCK]', Result::INFO => '[INFO ]'];
        $lines = [];
        foreach ($this->results as $r) {
            $lines[] = $icons[$r->level] . ' ' . $r->title . ($r->message !== '' ? ' — ' . $r->message : '');
            if ($r->fix !== '' && $r->level !== Result::OK) {
                $lines[] = '        → ' . $r->fix;
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
