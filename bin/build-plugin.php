<?php
/**
 * Builds dist/siteshifter/ (and dist/siteshifter.zip when ZipArchive exists):
 * plugin/ plus the shared core from src/, with the core's namespace prefixed
 * to SiteShifter\Core so it cannot clash with another copy of SiteShifter.
 *
 *   php bin/build-plugin.php
 */
$root = dirname(__DIR__);
$dist = $root . '/dist';
$out = $dist . '/siteshifter';

function ss_rrmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/** Prefixes SiteShifter\Foo to SiteShifter\Core\Foo (leaves "SiteShifter\'s" prose alone). */
function ss_prefix($code)
{
    return preg_replace('/(?<![A-Za-z0-9_])SiteShifter\\\\(?=[A-Z])/', 'SiteShifter\\\\Core\\\\', $code);
}

function ss_copy($from, $to, $transform = null)
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS)) as $f) {
        $rel = substr($f->getPathname(), strlen($from) + 1);
        $dest = $to . '/' . $rel;
        @mkdir(dirname($dest), 0755, true);
        $data = (string) file_get_contents($f->getPathname());
        if ($transform && $f->getExtension() === 'php') {
            $data = $transform($data);
        }
        file_put_contents($dest, $data);
    }
}

ss_rrmdir($out);
@mkdir($out, 0755, true);
ss_copy($root . '/plugin', $out);
// The core's CLI front-end is not needed inside WordPress.
ss_copy($root . '/src', $out . '/core', 'ss_prefix');
ss_rrmdir($out . '/core/Cli');
copy($root . '/LICENSE', $out . '/LICENSE.txt');

// Syntax check everything we shipped.
$bad = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->getExtension() === 'php') {
        exec('php -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $o, $rc);
        if ($rc !== 0) {
            fwrite(STDERR, implode("\n", $o) . "\n");
            $bad++;
        }
        $o = [];
    }
}
if ($bad) {
    exit(1);
}

if (class_exists('ZipArchive')) {
    $zipPath = $dist . '/siteshifter.zip';
    @unlink($zipPath);
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS)) as $f) {
        $zip->addFile($f->getPathname(), 'siteshifter/' . substr($f->getPathname(), strlen($out) + 1));
    }
    $zip->close();
    echo "Built {$zipPath}\n";
} else {
    echo "Built {$out} (no ZipArchive, zip skipped)\n";
}
