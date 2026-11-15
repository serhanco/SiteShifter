<?php

namespace SiteShifter\Package;

/**
 * What goes into a package: a profile (full site, structure only, settings
 * only, content only) or a custom selection of content types and parts.
 *
 * It only decides, as plain data: which tables are left out, which rows of a
 * table are kept (a SQL condition) and which file folders are packed. The
 * Dumper, Archiver and the importer apply it, so the CLI, installer and
 * WordPress plugin all behave the same way.
 */
class Scope
{
    public const PROFILES = ['full', 'structure', 'settings', 'content', 'custom'];

    /** Post types that describe how the site is built, not what it says. */
    public const STRUCTURAL_TYPES = [
        'nav_menu_item', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_block',
        'custom_css', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page',
        'elementor_library',
    ];

    /** @var array */
    private $o;

    private function __construct(array $o)
    {
        $this->o = $o;
    }

    public static function full(): self
    {
        return self::fromArray(['profile' => 'full']);
    }

    /**
     * @param array $in profile (string), and for "custom": types ("all" or string[]), media, users, comments,
     *                  settings, themes, plugins, other_tables, other_files, revisions (bool)
     */
    public static function fromArray(array $in): self
    {
        $profile = (string) ($in['profile'] ?? 'full');
        if (!in_array($profile, self::PROFILES, true)) {
            throw new \InvalidArgumentException("Unknown scope profile \"{$profile}\".");
        }
        $base = [
            'full' => ['types' => 'all', 'media' => true, 'users' => true, 'comments' => true, 'settings' => true, 'themes' => true, 'plugins' => true, 'other_tables' => true, 'other_files' => true, 'revisions' => true],
            'structure' => ['types' => self::STRUCTURAL_TYPES, 'media' => false, 'users' => false, 'comments' => false, 'settings' => true, 'themes' => true, 'plugins' => true, 'other_tables' => false, 'other_files' => false, 'revisions' => false],
            'settings' => ['types' => [], 'media' => false, 'users' => false, 'comments' => false, 'settings' => true, 'themes' => false, 'plugins' => false, 'other_tables' => false, 'other_files' => false, 'revisions' => false],
            'content' => ['types' => 'all', 'media' => true, 'users' => true, 'comments' => true, 'settings' => false, 'themes' => false, 'plugins' => false, 'other_tables' => true, 'other_files' => false, 'revisions' => false],
            'custom' => ['types' => 'all', 'media' => true, 'users' => true, 'comments' => true, 'settings' => true, 'themes' => true, 'plugins' => true, 'other_tables' => true, 'other_files' => true, 'revisions' => true],
        ][$profile];

        $o = $base;
        if ($profile === 'custom') {
            foreach ($base as $key => $default) {
                if (!array_key_exists($key, $in)) {
                    continue;
                }
                $o[$key] = $key === 'types' ? self::cleanTypes($in[$key]) : (bool) $in[$key];
            }
        }
        $o['profile'] = $profile;

        return new self($o);
    }

    /** @param mixed $types */
    private static function cleanTypes($types)
    {
        if ($types === 'all') {
            return 'all';
        }
        $list = is_array($types) ? $types : [];

        return array_values(array_unique(array_filter(array_map('strval', $list), static function ($t) {
            return preg_match('/^[a-z0-9_\-]{1,20}$/i', $t) === 1;
        })));
    }

    public function profile(): string
    {
        return $this->o['profile'];
    }

    public function isFull(): bool
    {
        return $this->o['profile'] === 'full';
    }

    public function toArray(): array
    {
        return $this->o;
    }

    /** True when any file at all is packed. */
    public function hasFiles(): bool
    {
        return $this->o['themes'] || $this->o['plugins'] || $this->o['media'] || $this->o['other_files'];
    }

    /**
     * Folders (relative to the WordPress root) to exclude, on top of $base.
     *
     * @return string[]
     */
    public function excludes(array $base): array
    {
        $out = $base;
        if (!$this->o['themes']) {
            $out[] = 'wp-content/themes';
        }
        if (!$this->o['plugins']) {
            $out[] = 'wp-content/plugins';
            $out[] = 'wp-content/mu-plugins';
        }
        if (!$this->o['media']) {
            $out[] = 'wp-content/uploads';
        }

        return array_values(array_unique($out));
    }

    /**
     * Folders that are the only ones packed, or [] when nothing narrows the file list.
     *
     * @return string[]
     */
    public function only(): array
    {
        if ($this->o['other_files']) {
            return [];
        }
        if (!$this->hasFiles()) {
            return ['.siteshifter-no-files']; // matches nothing: no files are packed
        }
        $only = [];
        if ($this->o['themes']) {
            $only[] = 'wp-content/themes';
        }
        if ($this->o['plugins']) {
            $only[] = 'wp-content/plugins';
            $only[] = 'wp-content/mu-plugins';
        }
        if ($this->o['media']) {
            $only[] = 'wp-content/uploads';
        }
        if ($this->o['themes'] || $this->o['plugins']) {
            $only[] = 'wp-content/languages';
        }

        return $only;
    }

    /**
     * @param string[] $tables every table that could be packed
     *
     * @return array{tables:string[],where:array<string,string>,skipped:string[],filtered:string[]}
     */
    public function tableRules(string $prefix, array $tables): array
    {
        $o = $this->o;
        $p = $prefix;
        $postWhere = $this->postCondition();
        $posts = $postWhere !== '0';
        $postIds = $posts ? 'SELECT `ID` FROM `' . $p . 'posts`' . ($postWhere === '' ? '' : ' WHERE ' . $postWhere) : '';
        $commentIds = 'SELECT `comment_ID` FROM `' . $p . 'comments` WHERE `comment_post_ID` IN (' . $postIds . ')';

        $keep = [];
        $where = [];
        foreach ($tables as $table) {
            if (strpos($table, $p) !== 0) {
                if ($o['other_tables']) {
                    $keep[] = $table;
                }
                continue;
            }
            $name = substr($table, strlen($p));
            switch ($name) {
                case 'options':
                    $include = $o['settings'];
                    break;
                case 'users':
                case 'usermeta':
                    $include = $o['users'];
                    break;
                case 'posts':
                    $include = $posts;
                    if ($include && $postWhere !== '') {
                        $where[$table] = $postWhere;
                    }
                    break;
                case 'postmeta':
                    $include = $posts;
                    if ($include && $postWhere !== '') {
                        $where[$table] = '`post_id` IN (' . $postIds . ')';
                    }
                    break;
                case 'term_relationships':
                    $include = $posts;
                    if ($include && $postWhere !== '') {
                        $where[$table] = '`object_id` IN (' . $postIds . ')';
                    }
                    break;
                case 'terms':
                case 'term_taxonomy':
                case 'termmeta':
                case 'links':
                    $include = $posts;
                    break;
                case 'comments':
                    $include = $posts && $o['comments'];
                    if ($include && $postWhere !== '') {
                        $where[$table] = '`comment_post_ID` IN (' . $postIds . ')';
                    }
                    break;
                case 'commentmeta':
                    $include = $posts && $o['comments'];
                    if ($include && $postWhere !== '') {
                        $where[$table] = '`comment_id` IN (' . $commentIds . ')';
                    }
                    break;
                default: // other tables that happen to share the WordPress prefix (plugins)
                    $include = $o['other_tables'];
            }
            if ($include) {
                $keep[] = $table;
            }
        }

        return [
            'tables' => $keep,
            'where' => $where,
            'skipped' => array_values(array_diff($tables, $keep)),
            'filtered' => array_keys($where),
        ];
    }

    /** SQL condition on the posts table: "" = every post, "0" = no posts at all. */
    private function postCondition(): string
    {
        $o = $this->o;
        $types = $o['types'];
        if ($types === 'all') {
            $cond = [];
            if (!$o['media']) {
                $cond[] = "`post_type` <> 'attachment'";
            }
            if (!$o['revisions']) {
                $cond[] = "`post_type` <> 'revision'";
            }

            return implode(' AND ', $cond);
        }
        $list = $types;
        if ($o['settings'] && $o['profile'] === 'custom') {
            $list = array_merge($list, self::STRUCTURAL_TYPES); // menus, templates and field groups travel with the settings
        }
        if ($o['media']) {
            $list[] = 'attachment';
        }
        $list = array_values(array_unique($list));
        if ($list === []) {
            return '0';
        }

        return '`post_type` IN (' . implode(', ', array_map(static function ($t) {
            return "'" . addslashes($t) . "'";
        }, $list)) . ')';
    }

    /**
     * Things the person must know or do because of what was left out.
     *
     * @return array<int,array{code:string,level:string}>
     */
    public function warnings(): array
    {
        $o = $this->o;
        $w = [];
        if ($this->isFull()) {
            return $w;
        }
        if (!$o['media'] && $o['types'] !== []) {
            $w[] = ['code' => 'media_excluded', 'level' => 'warning']; // copy wp-content/uploads by hand
        }
        if (!$o['users'] && $o['types'] !== []) {
            $w[] = ['code' => 'users_excluded', 'level' => 'warning']; // post authors need mapping on import
        }
        if (!$o['comments'] && $o['types'] === 'all') {
            $w[] = ['code' => 'comments_excluded', 'level' => 'info'];
        }
        if (!$o['settings']) {
            $w[] = ['code' => 'settings_excluded', 'level' => 'info'];
        }
        if (!$o['themes'] || !$o['plugins']) {
            $w[] = ['code' => 'code_excluded', 'level' => 'warning']; // theme/plugins must already exist at the target
        }
        if (is_array($o['types']) && $o['types'] !== []) {
            $w[] = ['code' => 'types_limited', 'level' => 'info'];
        }
        if (!$o['other_tables']) {
            $w[] = ['code' => 'plugin_tables_excluded', 'level' => 'warning']; // plugin data (forms, orders…) is not included
        }

        return $w;
    }

    /** What the manifest stores, so the importer knows the package is partial. */
    public function describe(array $rules): array
    {
        return $this->o + [
            'tables_skipped' => $rules['skipped'],
            'tables_filtered' => $rules['filtered'],
            'warnings' => array_column($this->warnings(), 'code'),
            'manual_uploads' => !$this->o['media'],
        ];
    }
}
