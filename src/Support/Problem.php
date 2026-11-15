<?php

namespace SiteShifter\Support;

/**
 * Turns a failure into a stable code, so the CLI, the installer and the
 * WordPress plugin can all show the same explanation and the same fix, and
 * so a job knows whether trying again can help.
 *
 * Codes never change meaning once released: translations, support answers
 * and tests refer to them.
 */
class Problem
{
    public const PERM_WRITE = 'SS_E_PERM_WRITE';
    public const DISK_FULL = 'SS_E_DISK_FULL';
    public const OPEN_BASEDIR = 'SS_E_OPEN_BASEDIR';
    public const FILE_LOCKED = 'SS_E_FILE_LOCKED';
    public const DB_CONNECT = 'SS_E_DB_CONNECT';
    public const DB_AUTH = 'SS_E_DB_AUTH';
    public const DB_PRIVILEGE = 'SS_E_DB_PRIVILEGE';
    public const DB_PACKET = 'SS_E_DB_PACKET';
    public const DB_LOCK = 'SS_E_DB_LOCK';
    public const DB_GONE = 'SS_E_DB_GONE';
    public const EXT_MISSING = 'SS_E_EXT_MISSING';
    public const ZIP_BAD = 'SS_E_ZIP_BAD';
    public const MEMORY = 'SS_E_MEMORY';
    public const TIMEOUT = 'SS_E_TIMEOUT';
    public const UNKNOWN = 'SS_E_UNKNOWN';

    /** code => [English title, English fix, can a retry help?] */
    private const CATALOG = [
        self::PERM_WRITE => ['A file or folder could not be written', 'Give the web server user write permission to the folder named in the message (usually 755 for folders and 644 for files, owned by the account that runs PHP), then resume.', false],
        self::DISK_FULL => ['The disk is full', 'Free some space (old packages, backups, caches) or ask your host to raise the disk quota, then resume.', false],
        self::OPEN_BASEDIR => ['PHP is not allowed to use that folder (open_basedir)', 'Ask your host to add the folder to open_basedir, or choose a folder that is inside the allowed paths.', false],
        self::FILE_LOCKED => ['A file is busy or locked', 'Another SiteShifter job or a backup tool is using it. Wait a minute and resume.', true],
        self::DB_CONNECT => ['The database server could not be reached', 'Check that the database host is correct and running. On shared hosting try "localhost" or the host name your provider shows. Then resume.', true],
        self::DB_AUTH => ['The database refused the user name or password', 'Check DB_USER and DB_PASSWORD in wp-config.php, and that the user may connect from this server.', false],
        self::DB_PRIVILEGE => ['The database user lacks a permission', 'Grant the database user ALL PRIVILEGES on this database (CREATE, DROP, ALTER, INSERT, SELECT, UPDATE, DELETE, LOCK TABLES), then resume.', false],
        self::DB_PACKET => ['A database row or statement is larger than the server allows', 'Raise max_allowed_packet (for example to 64M) in the MySQL settings or ask your host to, then resume.', false],
        self::DB_LOCK => ['The database was busy (lock wait or deadlock)', 'Another process is using the same tables. Try again in a moment; pause busy plugins or cron jobs if it keeps happening.', true],
        self::DB_GONE => ['The database connection was lost', 'The server closed the connection (timeout or restart). Resume; if it repeats, ask your host to raise wait_timeout.', true],
        self::EXT_MISSING => ['A required PHP extension is missing', 'Enable the mysqli and zip extensions in your PHP settings or ask your host.', false],
        self::ZIP_BAD => ['The zip file cannot be read', 'The file is incomplete or damaged. Create or upload the package again.', false],
        self::MEMORY => ['PHP ran out of memory', 'Raise memory_limit (256M or more) in php.ini or wp-config.php (WP_MEMORY_LIMIT), or ask your host, then resume.', false],
        self::TIMEOUT => ['PHP stopped the request because it took too long', 'Nothing is lost: the job continues from where it stopped. If it repeats, ask your host to raise max_execution_time.', true],
        self::UNKNOWN => ['Something unexpected went wrong', 'Resume to try again. If it repeats, send the message below to support.', true],
    ];

    /**
     * @return array{code:string,retryable:bool,title:string,fix:string,message:string}
     */
    public static function classify(\Throwable $e): array
    {
        $code = self::codeFor((string) $e->getMessage(), (int) $e->getCode());
        [$title, $fix, $retry] = self::CATALOG[$code];

        return ['code' => $code, 'retryable' => $retry, 'title' => $title, 'fix' => $fix, 'message' => $e->getMessage()];
    }

    /** @return array{0:string,1:string,2:bool}|null */
    public static function describe(string $code): ?array
    {
        return self::CATALOG[$code] ?? null;
    }

    /** @return string[] */
    public static function codes(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function isRetryable(string $code): bool
    {
        return self::CATALOG[$code][2] ?? true;
    }

    /** For PHP fatal errors caught at shutdown (error_get_last()). */
    public static function fromFatal(?array $error): ?string
    {
        if (!$error || !in_array($error['type'] ?? 0, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            return null;
        }
        $m = (string) ($error['message'] ?? '');
        if (stripos($m, 'allowed memory size') !== false || stripos($m, 'out of memory') !== false) {
            return self::MEMORY;
        }
        if (stripos($m, 'maximum execution time') !== false) {
            return self::TIMEOUT;
        }

        return null;
    }

    private static function codeFor(string $m, int $errno): string
    {
        if (stripos($m, 'open_basedir') !== false) {
            return self::OPEN_BASEDIR;
        }
        $byErrno = [
            1044 => self::DB_PRIVILEGE, 1142 => self::DB_PRIVILEGE, 1143 => self::DB_PRIVILEGE, 1227 => self::DB_PRIVILEGE, 1370 => self::DB_PRIVILEGE, 1419 => self::DB_PRIVILEGE,
            1045 => self::DB_AUTH,
            1153 => self::DB_PACKET, 2020 => self::DB_PACKET,
            1205 => self::DB_LOCK, 1213 => self::DB_LOCK,
            2006 => self::DB_GONE, 2013 => self::DB_GONE,
            2002 => self::DB_CONNECT, 2003 => self::DB_CONNECT, 2005 => self::DB_CONNECT, 2054 => self::DB_CONNECT,
            1114 => self::DISK_FULL, 1021 => self::DISK_FULL,
        ];
        if (isset($byErrno[$errno])) {
            return $byErrno[$errno];
        }
        $rules = [
            self::EXT_MISSING => '/extension is not installed/i',
            self::DISK_FULL => '/disk (is )?full|no space left|quota exceeded|error 28 from storage/i',
            self::PERM_WRITE => '/permission denied|cannot (write|create|open lock)|could not write|not writable|check folder permissions|could not add .* to the package/i',
            self::ZIP_BAD => '/zip error|incomplete|unsafe path in zip|cannot read .* from the zip/i',
            self::FILE_LOCKED => '/\bbusy\b|resource temporarily unavailable|locked/i',
            self::DB_PACKET => '/max_allowed_packet|packet too large/i',
            self::DB_AUTH => '/access denied for user/i',
            self::DB_CONNECT => '/could not connect to the database|connection refused|unknown mysql server host|no such file or directory.*mysql/i',
            self::DB_GONE => '/server has gone away|lost connection/i',
            self::DB_LOCK => '/lock wait timeout|deadlock/i',
            self::MEMORY => '/allowed memory size|out of memory/i',
            self::TIMEOUT => '/maximum execution time/i',
        ];
        foreach ($rules as $code => $pattern) {
            if (preg_match($pattern, $m)) {
                return $code;
            }
        }

        return self::UNKNOWN;
    }
}
