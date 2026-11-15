<?php
/**
 * Builds plugin/languages/siteshifter.pot from the __() style calls in plugin/.
 * Usage: php bin/make-pot.php
 */

$root = dirname(__DIR__) . '/plugin';
$funcs = ['__' => 0, 'esc_html__' => 0, 'esc_attr__' => 0, '_e' => 0, 'esc_html_e' => 0, 'esc_attr_e' => 0];
$found = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $tokens = token_get_all(file_get_contents($file->getPathname()));
    $rel = substr($file->getPathname(), strlen(dirname($root)) + 1);
    $comment = '';
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_COMMENT && stripos($t[1], 'translators:') !== false) {
            $comment = trim(preg_replace('#^/\*+|\*+/$|^//#', '', $t[1]));
            $comment = trim(preg_replace('/\s*\n\s*\*?\s*/', ' ', $comment));
            continue;
        }
        if (!is_array($t) || $t[0] !== T_STRING || !isset($funcs[$t[1]])) {
            continue;
        }
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (($tokens[$j] ?? null) !== '(') {
            continue;
        }
        $j++;
        $text = '';
        $ok = false;
        while (isset($tokens[$j])) {
            $x = $tokens[$j];
            if (is_array($x) && $x[0] === T_CONSTANT_ENCAPSED_STRING) {
                $q = $x[1][0];
                $body = substr($x[1], 1, -1);
                $text .= $q === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body) : stripcslashes($body);
                $ok = true;
            } elseif ($x === '.' || (is_array($x) && $x[0] === T_WHITESPACE)) {
                // concatenation of literals
            } else {
                break;
            }
            $j++;
        }
        if ($ok && $text !== '') {
            $found[$text]['refs'][] = $rel;
            if ($comment !== '') {
                $found[$text]['comment'] = $comment;
            }
        }
        $comment = '';
    }
}
ksort($found);
$out = "# SiteShifter translation template.\n# by Serhan Demirel - https://serhandemirel.com\nmsgid \"\"\nmsgstr \"\"\n\"Project-Id-Version: SiteShifter\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"MIME-Version: 1.0\\n\"\n\n";
$q = static function ($s) {
    return '"' . str_replace(["\\", '"', "\n"], ['\\\\', '\\"', '\\n'], $s) . '"';
};
foreach ($found as $text => $info) {
    if (!empty($info['comment'])) {
        $out .= '#. ' . $info['comment'] . "\n";
    }
    $out .= '#: ' . implode(' ', array_slice(array_unique($info['refs']), 0, 2)) . "\n";
    if (strpos($text, '%') !== false) {
        $out .= "#, php-format\n";
    }
    $out .= 'msgid ' . $q($text) . "\nmsgstr \"\"\n\n";
}
file_put_contents($root . '/languages/siteshifter.pot', $out);
echo count($found) . " strings written to plugin/languages/siteshifter.pot\n";
