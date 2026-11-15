<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Admin;

defined('ABSPATH') || exit;

/**
 * Translated title and fix for each stable error code (see the core's Problem class).
 */
class ProblemText
{
    /** @return array<string,array{title:string,fix:string}> */
    public static function all()
    {
        return [
            'SS_E_PERM_WRITE' => [
                'title' => __('A file or folder could not be written', 'siteshifter'),
                'fix' => __('Give the web server user write permission to the folder named in the message (usually 755 for folders and 644 for files, owned by the account that runs PHP), then resume.', 'siteshifter'),
            ],
            'SS_E_DISK_FULL' => [
                'title' => __('The disk is full', 'siteshifter'),
                'fix' => __('Free some space (old packages, backups, caches) or ask your host to raise the disk quota, then resume.', 'siteshifter'),
            ],
            'SS_E_OPEN_BASEDIR' => [
                'title' => __('PHP is not allowed to use that folder (open_basedir)', 'siteshifter'),
                'fix' => __('Ask your host to add the folder to open_basedir, or choose a folder that is inside the allowed paths.', 'siteshifter'),
            ],
            'SS_E_FILE_LOCKED' => [
                'title' => __('A file is busy or locked', 'siteshifter'),
                'fix' => __('Another SiteShifter job or a backup tool is using it. Wait a minute and resume.', 'siteshifter'),
            ],
            'SS_E_DB_CONNECT' => [
                'title' => __('The database server could not be reached', 'siteshifter'),
                'fix' => __('Check that the database host is correct and running. On shared hosting try "localhost" or the host name your provider shows. Then resume.', 'siteshifter'),
            ],
            'SS_E_DB_AUTH' => [
                'title' => __('The database refused the user name or password', 'siteshifter'),
                'fix' => __('Check the database user name and the password stored in wp-config.php, and that the user may connect from this server.', 'siteshifter'),
            ],
            'SS_E_DB_PRIVILEGE' => [
                'title' => __('The database user lacks a permission', 'siteshifter'),
                'fix' => __('Grant the database user ALL PRIVILEGES on this database (CREATE, DROP, ALTER, INSERT, SELECT, UPDATE, DELETE, LOCK TABLES), then resume.', 'siteshifter'),
            ],
            'SS_E_DB_PACKET' => [
                'title' => __('A database row or statement is larger than the server allows', 'siteshifter'),
                'fix' => __('Raise max_allowed_packet (for example to 64M) in the MySQL settings or ask your host to, then resume.', 'siteshifter'),
            ],
            'SS_E_DB_LOCK' => [
                'title' => __('The database was busy (lock wait or deadlock)', 'siteshifter'),
                'fix' => __('Another process is using the same tables. Try again in a moment; pause busy plugins or cron jobs if it keeps happening.', 'siteshifter'),
            ],
            'SS_E_DB_GONE' => [
                'title' => __('The database connection was lost', 'siteshifter'),
                'fix' => __('The server closed the connection (timeout or restart). Resume; if it repeats, ask your host to raise wait_timeout.', 'siteshifter'),
            ],
            'SS_E_EXT_MISSING' => [
                'title' => __('A required PHP extension is missing', 'siteshifter'),
                'fix' => __('Enable the mysqli and zip extensions in your PHP settings or ask your host.', 'siteshifter'),
            ],
            'SS_E_ZIP_BAD' => [
                'title' => __('The zip file cannot be read', 'siteshifter'),
                'fix' => __('The file is incomplete or damaged. Create or upload the package again.', 'siteshifter'),
            ],
            'SS_E_MEMORY' => [
                'title' => __('PHP ran out of memory', 'siteshifter'),
                'fix' => __('Raise memory_limit (256M or more) in php.ini or wp-config.php (WP_MEMORY_LIMIT), or ask your host, then resume.', 'siteshifter'),
            ],
            'SS_E_TIMEOUT' => [
                'title' => __('PHP stopped the request because it took too long', 'siteshifter'),
                'fix' => __('Nothing is lost: the job continues from where it stopped. If it repeats, ask your host to raise max_execution_time.', 'siteshifter'),
            ],
            'SS_E_UNKNOWN' => [
                'title' => __('Something unexpected went wrong', 'siteshifter'),
                'fix' => __('Resume to try again. If it repeats, send the message below to support.', 'siteshifter'),
            ],
        ];
    }
}
