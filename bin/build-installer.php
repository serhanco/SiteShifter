<?php
/**
 * Builds dist/installer.php: every class in src/ plus installer/ui.php in
 * one file, so it can be uploaded next to a package and opened in a browser.
 *
 *   php bin/build-installer.php
 */
$root = dirname(__DIR__);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
$paths = [];
foreach ($files as $file) {
    if ($file->getExtension() === 'php' && strpos($file->getPathname(), '/Cli/') === false) {
        $paths[] = $file->getPathname();
    }
}
sort($paths);

$out = "<?php\n/**\n * SiteShifter installer — shift WordPress sites safely.\n * by Serhan Demirel — https://serhandemirel.com\n * GPL-2.0-or-later — https://github.com/serhanco/SiteShifter\n *\n * Upload this file next to your SiteShifter package (or your .sql and .zip\n * export) in the new site's folder and open it in a browser.\n * Built " . gmdate('Y-m-d H:i') . " UTC. Do not edit: generated from src/ and installer/ui.php.\n */\n\n";

foreach ($paths as $path) {
    $code = (string) file_get_contents($path);
    $code = preg_replace('/^<\?php\s*/', '', $code);
    if (!preg_match('/^namespace\s+([^;]+);\s*$/m', $code, $m)) {
        fwrite(STDERR, "No namespace in {$path}\n");
        exit(1);
    }
    $code = preg_replace('/^namespace\s+[^;]+;\s*$/m', '', $code, 1);
    $out .= 'namespace ' . $m[1] . " {\n" . trim($code) . "\n}\n\n";
}

$ui = (string) file_get_contents($root . '/installer/ui.php');
$ui = preg_replace('/^<\?php\s*/', '', $ui);
$out .= "namespace {\n" . $ui . "<?php\n}\n";

@mkdir($root . '/dist', 0755, true);
file_put_contents($root . '/dist/installer.php', $out);
passthru('php -l ' . escapeshellarg($root . '/dist/installer.php'), $code);
exit($code);
