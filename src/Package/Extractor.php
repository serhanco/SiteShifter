<?php

namespace SiteShifter\Package;

/**
 * Extracts a zip into the site folder in resumable batches.
 *
 * Works with SiteShifter packages and with hand-made zips (a whole
 * public_html, a single top-level folder, or just the contents of
 * wp-content): detectLayout() finds where WordPress lives inside the zip
 * and where it has to go.
 */
class Extractor
{
    /** @var string */
    private $zipPath;

    /** @var string */
    private $dest;

    /** @var array{strip:string,target:string} */
    private $layout;

    /** @var string[] entries (after stripping) never written, e.g. wp-config.php */
    private $skip;

    public function __construct(string $zipPath, string $dest, array $layout, array $skip = [])
    {
        $this->zipPath = $zipPath;
        $this->dest = rtrim(str_replace('\\', '/', $dest), '/');
        $this->layout = $layout;
        $this->skip = $skip;
    }

    public static function initialState(): array
    {
        return ['index' => 0, 'total' => null, 'files' => 0, 'bytes' => 0, 'done' => false];
    }

    /**
     * Works out where WordPress is inside a list of zip entry names.
     *
     * @param string[] $names
     * @return array{strip:string,target:string,has_core:bool,has_config:bool,has_content:bool,sql:string[]}
     */
    public static function detectLayout(array $names): array
    {
        $strip = null;
        $target = '';
        $sql = [];
        foreach ($names as $name) {
            $n = str_replace('\\', '/', $name);
            if (preg_match('/\.sql(\.gz)?$/i', $n)) {
                $sql[] = $n;
            }
            if (preg_match('#^(.*?)wp-includes/version\.php$#', $n, $m) || preg_match('#^(.*?)wp-content/(plugins|themes|uploads)/#', $n, $m)) {
                if ($strip === null || strlen($m[1]) < strlen($strip)) {
                    $strip = $m[1];
                }
            }
        }
        if ($strip === null) {
            // Only the contents of wp-content: themes/, plugins/, uploads/ at some level.
            foreach ($names as $name) {
                $n = str_replace('\\', '/', $name);
                if (preg_match('#^(.*?)(plugins|themes|uploads)/#', $n, $m) && ($strip === null || strlen($m[1]) < strlen($strip))) {
                    $strip = $m[1];
                }
            }
            $target = 'wp-content/';
        }
        $strip = (string) $strip;
        $set = array_flip(array_map(static function ($n) {
            return str_replace('\\', '/', $n);
        }, $names));

        return [
            'strip' => $strip,
            'target' => $target,
            'has_core' => $target === '' && isset($set[$strip . 'wp-includes/version.php']),
            'has_config' => $target === '' && isset($set[$strip . 'wp-config.php']),
            'has_content' => true,
            'sql' => $sql,
        ];
    }

    /** @return string[] */
    public static function entryNames(string $zipPath): array
    {
        $zip = self::openZip($zipPath);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }

    /** Reads one file from the zip relative to the detected WordPress root, or null. */
    public static function readEntry(string $zipPath, array $layout, string $relative): ?string
    {
        $zip = self::openZip($zipPath);
        $name = $layout['target'] === '' ? $layout['strip'] . $relative : null;
        if ($layout['target'] !== '' && strpos($relative, $layout['target']) === 0) {
            $name = $layout['strip'] . substr($relative, strlen($layout['target']));
        }
        $data = $name === null ? false : $zip->getFromName($name);
        $zip->close();

        return $data === false ? null : $data;
    }

    public function run(array $state, float $deadline): array
    {
        $zip = self::openZip($this->zipPath);
        $state['total'] = $zip->numFiles;
        $strip = $this->layout['strip'];
        $target = $this->layout['target'];
        while ($state['index'] < $zip->numFiles && microtime(true) < $deadline) {
            $i = $state['index']++;
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($strip !== '' && strpos($name, $strip) !== 0) {
                continue;
            }
            $relative = $target . substr($name, strlen($strip));
            if ($relative === '' || substr($relative, -1) === '/' || in_array($relative, $this->skip, true)) {
                continue;
            }
            if (!empty($this->layout['skip_regex']) && preg_match($this->layout['skip_regex'], $relative)) {
                continue;
            }
            foreach ($this->layout['skip_prefixes'] ?? [] as $skipPrefix) {
                if (strpos($relative, $skipPrefix) === 0) {
                    continue 2;
                }
            }
            if (isset($this->layout['rename'][$relative])) {
                $relative = $this->layout['rename'][$relative];
            }
            if (!self::safePath($relative)) {
                throw new \RuntimeException("Unsafe path in zip: {$name}");
            }
            $out = $this->dest . '/' . $relative;
            $dir = dirname($out);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create folder {$dir}");
            }
            $in = $zip->getStream($name);
            if ($in === false) {
                throw new \RuntimeException("Cannot read {$name} from the zip.");
            }
            $fh = @fopen($out, 'wb');
            if ($fh === false) {
                fclose($in);
                throw new \RuntimeException("Cannot write {$out} — check folder permissions.");
            }
            $bytes = stream_copy_to_stream($in, $fh);
            fclose($in);
            fclose($fh);
            if ($bytes === false) {
                throw new \RuntimeException("Writing {$out} failed — is the disk full?");
            }
            $state['files']++;
            $state['bytes'] += $bytes;
        }
        $zip->close();
        $state['done'] = $state['index'] >= $state['total'];

        return $state;
    }

    /** Total uncompressed size of the entries, for the disk-space check. */
    public static function uncompressedSize(string $zipPath): int
    {
        $zip = self::openZip($zipPath);
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += (int) ($stat['size'] ?? 0);
        }
        $zip->close();

        return $total;
    }

    private static function safePath(string $path): bool
    {
        return strpos($path, "\0") === false
            && !preg_match('#(^|/)\.\.(/|$)#', $path)
            && $path[0] !== '/'
            && !preg_match('#^[A-Za-z]:#', $path);
    }

    private static function openZip(string $path): \ZipArchive
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('The PHP zip extension is not installed.');
        }
        $zip = new \ZipArchive();
        $result = $zip->open($path);
        if ($result !== true) {
            throw new \RuntimeException("Cannot open {$path} (zip error {$result}). The file may be incomplete — upload it again.");
        }

        return $zip;
    }
}
