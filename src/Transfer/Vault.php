<?php

namespace SiteShifter\Transfer;

/**
 * A small encrypted store for connection secrets (cloud tokens, clone keys).
 *
 * It lives in a file next to the jobs, not in the database: an import in
 * Replace mode swaps the whole database, which would otherwise lose the
 * connection half way. The file starts with "<?php exit;" so a web server
 * never shows it, and each value is sealed with a key derived from the site's
 * own secret keys, so a copy of the file alone reveals nothing. Packages never
 * contain it (the work folder is excluded).
 */
class Vault
{
    const GUARD = "<?php exit; ?>\n";

    /** @var string */
    private $file;

    /** @var string 32 bytes */
    private $key;

    /**
     * @param string $siteSecret something only this site knows (WordPress: its auth salts)
     */
    public function __construct($file, $siteSecret)
    {
        if (strlen((string) $siteSecret) < 16) {
            throw new \InvalidArgumentException('The site has no secret keys to protect saved connections with. Add the keys to wp-config.php.');
        }
        $this->file = (string) $file;
        $this->key = hash_hmac('sha256', 'siteshifter-vault-v1', (string) $siteSecret, true);
    }

    /** @return mixed|null null when missing or unreadable (e.g. the site's keys changed) */
    public function get($name)
    {
        $all = $this->read();
        if (!isset($all[$name])) {
            return null;
        }
        $plain = $this->open((string) $all[$name]);

        return $plain === null ? null : json_decode($plain, true);
    }

    public function set($name, $value)
    {
        $all = $this->read();
        $all[$name] = $this->seal(json_encode($value));
        $this->write($all);
    }

    public function delete($name)
    {
        $all = $this->read();
        unset($all[$name]);
        $this->write($all);
    }

    private function read()
    {
        if (!is_file($this->file)) {
            return [];
        }
        $raw = (string) file_get_contents($this->file);
        $data = json_decode(substr($raw, strlen(self::GUARD)), true);

        return is_array($data) ? $data : [];
    }

    private function write(array $all)
    {
        $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, self::GUARD . json_encode($all)) === false) {
            throw new \RuntimeException('Cannot save the connection settings. Check folder permissions.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot save the connection settings. Check folder permissions.');
        }
    }

    private function seal($plain)
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
        }
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        return 'g1:' . base64_encode($iv . $tag . $cipher);
    }

    private function open($sealed)
    {
        $kind = substr($sealed, 0, 3);
        $bin = base64_decode(substr($sealed, 3), true);
        if ($bin === false) {
            return null;
        }
        if ($kind === 's1:' && function_exists('sodium_crypto_secretbox_open')) {
            $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $plain = strlen($bin) > $n ? sodium_crypto_secretbox_open(substr($bin, $n), substr($bin, 0, $n), $this->key) : false;

            return $plain === false ? null : $plain;
        }
        if ($kind === 'g1:' && strlen($bin) > 28) {
            $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));

            return $plain === false ? null : $plain;
        }

        return null;
    }
}
