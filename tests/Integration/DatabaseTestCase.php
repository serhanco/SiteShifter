<?php

namespace SiteShifter\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SiteShifter\Database\Connection;

/**
 * Integration tests need a MySQL/MariaDB server. Configure with
 * SS_DB_HOST, SS_DB_USER, SS_DB_PASS (defaults: localhost / ss / ss).
 * Each test gets a fresh database.
 */
abstract class DatabaseTestCase extends TestCase
{
    /** @var Connection */
    protected $db;

    /** @var string */
    protected $dbName;

    protected function setUp(): void
    {
        $host = getenv('SS_DB_HOST') ?: 'localhost';
        $user = getenv('SS_DB_USER') ?: 'ss';
        $pass = getenv('SS_DB_PASS') ?: 'ss';
        $this->dbName = 'ss_test_' . substr(md5(static::class . $this->getName()), 0, 10);
        try {
            $admin = new Connection($host, $user, $pass, '');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('No test database: ' . $e->getMessage());
        }
        $admin->exec('DROP DATABASE IF EXISTS ' . Connection::ident($this->dbName));
        $admin->exec('CREATE DATABASE ' . Connection::ident($this->dbName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci');
        $this->db = new Connection($host, $user, $pass, $this->dbName);
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->exec('DROP DATABASE IF EXISTS ' . Connection::ident($this->dbName));
        }
    }

    /** Minimal WordPress-shaped schema plus a plugin table without a primary key. */
    protected function createWordPressTables(string $prefix = 'wp_'): void
    {
        $this->db->exec("CREATE TABLE {$prefix}options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'yes', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name))");
        $this->db->exec("CREATE TABLE {$prefix}posts (ID bigint unsigned NOT NULL AUTO_INCREMENT, post_content longtext NOT NULL, post_title text NOT NULL, guid varchar(255) NOT NULL DEFAULT '', post_type varchar(20) NOT NULL DEFAULT 'post', post_name varchar(200) NOT NULL DEFAULT '', post_parent bigint unsigned NOT NULL DEFAULT 0, post_author bigint unsigned NOT NULL DEFAULT 0, comment_count bigint NOT NULL DEFAULT 0, PRIMARY KEY (ID))");
        $this->db->exec("CREATE TABLE {$prefix}postmeta (meta_id bigint unsigned NOT NULL AUTO_INCREMENT, post_id bigint unsigned NOT NULL DEFAULT 0, meta_key varchar(255) DEFAULT NULL, meta_value longtext, PRIMARY KEY (meta_id))");
        $this->db->exec("CREATE TABLE {$prefix}icl_strings (lang varchar(7), value text)");
    }
}
