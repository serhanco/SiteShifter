<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Installer\ConfigWriter;
use SiteShifter\Installer\ServerFiles;
use SiteShifter\Package\Extractor;
use SiteShifter\Support\WpConfigReader;

class InstallerPartsTest extends TestCase
{
    private const CONFIG = <<<'PHP'
<?php
define( 'DB_NAME', 'old_db' );
define('DB_USER', getenv('DB_USER'));
define( 'DB_PASSWORD', 'not-a-secret' );
define( 'DB_HOST', 'localhost' );
define( 'WP_HOME', 'http://old.test' );
define( 'COOKIE_DOMAIN', '.old.test' );
$table_prefix = 'abc_';
/* That's all, stop editing! Happy publishing. */
require_once ABSPATH . 'wp-settings.php';
PHP;

    public function testRewritesExistingConfig(): void
    {
        $db = ['host' => 'db.host:3307', 'user' => 'new_user', 'pass' => "x'y\\z", 'name' => 'new_db'];
        $out = (new ConfigWriter())->write(self::CONFIG, $db, 'abc_', 'https://new.com/', '/var/www');

        $parsed = WpConfigReader::parse($out);
        $this->assertSame('new_db', $parsed['name']);
        $this->assertSame('new_user', $parsed['user']);
        $this->assertSame("x'y\\z", $parsed['pass']);
        $this->assertSame('db.host:3307', $parsed['host']);
        $this->assertSame('abc_', $parsed['prefix']);
        $this->assertStringContainsString("define( 'WP_HOME', 'https://new.com' );", $out);
        $this->assertStringNotContainsString('.old.test', $out);
        $this->assertStringNotContainsString('getenv', $out);
        $this->assertSame(1, substr_count($out, "'DB_NAME'"));
        $this->assertNotFalse(@token_get_all($out));
        $this->assertSame(0, $this->lint($out), 'generated wp-config.php must be valid PHP');
    }

    public function testFreshConfigHasSaltsAndIsValidPhp(): void
    {
        $out = (new ConfigWriter())->write(null, ['host' => 'localhost', 'user' => 'u', 'pass' => 'p', 'name' => 'n'], 'wp_', 'https://new.com', '/x');
        $this->assertSame(8, preg_match_all("/define\\( '(AUTH|SECURE_AUTH|LOGGED_IN|NONCE)_(KEY|SALT)'/", $out));
        $this->assertSame('wp_', WpConfigReader::parse($out)['prefix']);
        $this->assertSame(0, $this->lint($out));
    }

    private function lint(string $code): int
    {
        $f = tempnam(sys_get_temp_dir(), 'cfg');
        file_put_contents($f, $code);
        exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $code);

        return $code;
    }

    public function testServerFilesFixPathsAndDisableMissingPrepend(): void
    {
        $dir = sys_get_temp_dir() . '/ss-sf-' . uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/wordfence-waf.php", '<?php');
        file_put_contents("{$dir}/.user.ini", "auto_prepend_file = '/home/old/public_html/wordfence-waf.php'\n");
        file_put_contents("{$dir}/.htaccess", "php_value auto_prepend_file '/somewhere/else.php'\n# BEGIN WordPress\nRewriteBase /old/\nRewriteRule . /old/index.php [L]\n# END WordPress\n");

        (new ServerFiles($dir))->fix('/home/old/public_html', 'https://new.com/blog');

        $this->assertSame("auto_prepend_file = '{$dir}/wordfence-waf.php'\n", file_get_contents("{$dir}/.user.ini"));
        $ht = file_get_contents("{$dir}/.htaccess");
        $this->assertStringContainsString('# SiteShifter: disabled', $ht);
        $this->assertStringContainsString('RewriteBase /blog/', $ht);
        $this->assertStringContainsString('RewriteRule . /blog/index.php [L]', $ht);
    }

    public function testDetectsZipLayouts(): void
    {
        $full = Extractor::detectLayout(['public_html/wp-config.php', 'public_html/wp-includes/version.php', 'public_html/wp-content/themes/x/style.css', 'db.sql']);
        $this->assertSame('public_html/', $full['strip']);
        $this->assertSame('', $full['target']);
        $this->assertTrue($full['has_core']);
        $this->assertTrue($full['has_config']);
        $this->assertSame(['db.sql'], $full['sql']);

        $content = Extractor::detectLayout(['wp-content/plugins/a/a.php', 'wp-content/uploads/1.jpg']);
        $this->assertSame('', $content['strip']);
        $this->assertFalse($content['has_core']);

        $inner = Extractor::detectLayout(['plugins/a/a.php', 'themes/t/style.css', 'uploads/2024/a.jpg']);
        $this->assertSame('', $inner['strip']);
        $this->assertSame('wp-content/', $inner['target']);

        $package = Extractor::detectLayout(['siteshifter.json', 'database.sql', 'files/wp-includes/version.php', 'files/index.php']);
        $this->assertSame('files/', $package['strip']);
    }
}
