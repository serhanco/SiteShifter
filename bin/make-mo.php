<?php
/**
 * Compiles plugin/languages/*.po into .mo files (no gettext tools needed).
 * Usage: php bin/make-mo.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

foreach (glob(dirname(__DIR__) . '/plugin/languages/*.po') as $po) {
    $entries = SiteShifter\Support\PoFile::parse(file_get_contents($po));
    file_put_contents(substr($po, 0, -3) . '.mo', SiteShifter\Support\PoFile::compile($entries));
    echo basename($po) . ': ' . (count($entries) - 1) . " strings\n";
}
