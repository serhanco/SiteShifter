<?php

namespace SiteShifter\Replace;

/**
 * Walks a PHP-serialized string and rebuilds it, passing every string
 * payload through a callback and fixing the length prefixes.
 *
 * Returns null when the input is not a single, complete serialized
 * value; the caller then falls back to plain replacement.
 */
class SerializedRewriter
{
    /** @var callable(string):string */
    private $onString;

    /** @var string */
    private $data = '';

    /** @var int */
    private $pos = 0;

    /** @var int */
    private $len = 0;

    public function __construct(callable $onString)
    {
        $this->onString = $onString;
    }

    public function rewrite(string $data): ?string
    {
        $trimmed = rtrim($data);
        $trailing = (string) substr($data, strlen($trimmed));
        $this->data = $trimmed;
        $this->pos = 0;
        $this->len = strlen($trimmed);

        try {
            $out = $this->value();
        } catch (\UnexpectedValueException $e) {
            return null;
        }
        if ($this->pos !== $this->len) {
            return null;
        }

        return $out . $trailing;
    }

    private function value(): string
    {
        $type = $this->char();
        switch ($type) {
            case 'N':
                $this->expect('N;');
                return 'N;';
            case 'b':
            case 'i':
            case 'd':
            case 'r':
            case 'R':
                return $this->scalar($type);
            case 's':
                return $this->string();
            case 'a':
                return $this->arrayValue();
            case 'O':
                return $this->objectValue();
            case 'C':
                return $this->customObject();
            case 'E':
                return $this->enumValue();
        }
        throw new \UnexpectedValueException('Unknown type ' . $type);
    }

    private function scalar(string $type): string
    {
        $this->expect($type . ':');
        $end = strpos($this->data, ';', $this->pos);
        if ($end === false) {
            throw new \UnexpectedValueException('Unterminated scalar');
        }
        $raw = substr($this->data, $this->pos, $end - $this->pos);
        if (!preg_match('/^[-+0-9.eEINFA]+$/', $raw)) {
            throw new \UnexpectedValueException('Bad scalar');
        }
        $this->pos = $end + 1;

        return $type . ':' . $raw . ';';
    }

    private function string(): string
    {
        $this->expect('s:');
        $payload = $this->lengthPrefixed();
        $this->expect(';');
        $new = ($this->onString)($payload);

        return 's:' . strlen($new) . ':"' . $new . '";';
    }

    private function arrayValue(): string
    {
        $this->expect('a:');
        $count = $this->int(':');
        $this->expect('{');
        $out = '';
        for ($i = 0; $i < $count; $i++) {
            $out .= $this->key();
            $out .= $this->value();
        }
        $this->expect('}');

        return 'a:' . $count . ':{' . $out . '}';
    }

    private function objectValue(): string
    {
        $this->expect('O:');
        $class = $this->lengthPrefixed();
        $this->expect(':');
        $count = $this->int(':');
        $this->expect('{');
        $out = '';
        for ($i = 0; $i < $count; $i++) {
            $out .= $this->key();
            $out .= $this->value();
        }
        $this->expect('}');

        return 'O:' . strlen($class) . ':"' . $class . '":' . $count . ':{' . $out . '}';
    }

    /**
     * C:<len>:"Class":<len>:{payload} — the payload format belongs to the
     * class's own serializer, so it is copied untouched.
     */
    private function customObject(): string
    {
        $this->expect('C:');
        $class = $this->lengthPrefixed();
        $this->expect(':');
        $size = $this->int(':');
        $this->expect('{');
        if ($this->pos + $size > $this->len) {
            throw new \UnexpectedValueException('Custom payload overflow');
        }
        $payload = substr($this->data, $this->pos, $size);
        $this->pos += $size;
        $this->expect('}');

        return 'C:' . strlen($class) . ':"' . $class . '":' . $size . ':{' . $payload . '}';
    }

    private function enumValue(): string
    {
        $this->expect('E:');
        $name = $this->lengthPrefixed();
        $this->expect(';');

        return 'E:' . strlen($name) . ':"' . $name . '";';
    }

    /** Array and object keys are ints or strings; string keys are never rewritten. */
    private function key(): string
    {
        $type = $this->char();
        if ($type === 'i') {
            return $this->scalar('i');
        }
        if ($type === 's') {
            $this->expect('s:');
            $key = $this->lengthPrefixed();
            $this->expect(';');

            return 's:' . strlen($key) . ':"' . $key . '";';
        }
        throw new \UnexpectedValueException('Bad key type');
    }

    /** Reads <len>:"<bytes>" and returns the bytes. */
    private function lengthPrefixed(): string
    {
        $size = $this->int(':');
        $this->expect('"');
        if ($this->pos + $size > $this->len) {
            throw new \UnexpectedValueException('String overflow');
        }
        $bytes = substr($this->data, $this->pos, $size);
        $this->pos += $size;
        $this->expect('"');

        return $bytes;
    }

    private function int(string $terminator): int
    {
        $end = strpos($this->data, $terminator, $this->pos);
        if ($end === false) {
            throw new \UnexpectedValueException('Missing terminator');
        }
        $raw = substr($this->data, $this->pos, $end - $this->pos);
        if (!ctype_digit($raw)) {
            throw new \UnexpectedValueException('Bad length');
        }
        $this->pos = $end + 1;

        return (int) $raw;
    }

    private function char(): string
    {
        if ($this->pos >= $this->len) {
            throw new \UnexpectedValueException('Unexpected end');
        }

        return $this->data[$this->pos];
    }

    private function expect(string $token): void
    {
        $size = strlen($token);
        if ($this->pos + $size > $this->len || substr_compare($this->data, $token, $this->pos, $size) !== 0) {
            throw new \UnexpectedValueException('Expected ' . $token);
        }
        $this->pos += $size;
    }
}
