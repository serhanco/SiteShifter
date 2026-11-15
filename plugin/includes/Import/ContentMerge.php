<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Import;

use SiteShifter\Database\Connection;

defined('ABSPATH') || exit;

/**
 * Merge mode for content (posts, comments, terms): rows of the package are added next to the
 * ones this site has and every ID that points at another row is translated to the new ID.
 * An ID that cannot be translated is never guessed: it is cleared and written to the flags list.
 */
class ContentMerge
{
    const TABLES = ['posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'comments', 'commentmeta'];

    /** More package posts than this are not merged in one request: use Replace for such sites. */
    const MAX_POSTS = 20000;

    /** Meta that holds one post ID. */
    const ID_META = ['_thumbnail_id'];

    /** @var Connection */
    private $db;
    private $s;
    private $l;

    /** @var array<int,int> */
    private $posts = [];
    private $terms = [];
    private $comments = [];

    /** @var array */
    private $flags = [];

    /** @var array<int,int> package user ID => user ID here */
    private $users;

    /** @var MergePolicy */
    private $policy;

    public function __construct(Connection $db, $stage, $live, array $userMap, MergePolicy $policy)
    {
        $this->db = $db;
        $this->s = $stage;
        $this->l = $live;
        $this->users = $userMap;
        $this->policy = $policy;
    }

    public static function available(Connection $db, $stage)
    {
        $have = $db->tables($stage);
        foreach (['posts', 'postmeta'] as $t) {
            if (!in_array($stage . $t, $have, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{stats:array,flags:array,post_map:array} */
    public function apply()
    {
        $count = (int) $this->db->value('SELECT COUNT(*) FROM ' . $this->t('s', 'posts'));
        if ($count > self::MAX_POSTS) {
            throw new \InvalidArgumentException("The package has {$count} posts, too many to merge in one go. Use Replace for a site this size.");
        }
        $stats = ['posts_added' => 0, 'posts_overwritten' => 0, 'posts_skipped' => 0, 'posts_renamed' => 0, 'terms_added' => 0, 'comments_added' => 0];
        $this->terms($stats);
        $changed = $this->posts($stats);
        $this->fixReferences($changed);
        $this->relationships($changed);
        $this->comments($changed, $stats);

        return ['stats' => $stats, 'flags' => $this->flags, 'post_map' => $this->posts];
    }

    private function t($which, $name)
    {
        return Connection::ident(($which === 's' ? $this->s : $this->l) . $name);
    }

    private function common($table)
    {
        $live = array_column($this->db->rows('SHOW COLUMNS FROM ' . $this->t('l', $table)), 'Field');
        $stage = array_column($this->db->rows('SHOW COLUMNS FROM ' . $this->t('s', $table)), 'Field');

        return array_values(array_diff(array_intersect($live, $stage), [$table === 'posts' ? 'ID' : ($table === 'comments' ? 'comment_ID' : '')]));
    }

    private function flag($kind, $id, $what)
    {
        if (count($this->flags) < 500) {
            $this->flags[] = ['kind' => $kind, 'id' => (int) $id, 'what' => $what];
        }
    }

    // ------------------------------------------------------------- terms

    private function terms(array &$stats)
    {
        if (!in_array($this->s . 'term_taxonomy', $this->db->tables($this->s), true)) {
            return;
        }
        $rows = $this->db->rows('SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.description, tt.parent, t.name, t.slug, t.term_group FROM ' . $this->t('s', 'term_taxonomy') . ' tt JOIN ' . $this->t('s', 'terms') . ' t ON t.term_id = tt.term_id ORDER BY tt.term_taxonomy_id');
        $newTerm = [];
        foreach ($rows as $r) {
            $found = $this->db->value('SELECT tt.term_taxonomy_id FROM ' . $this->t('l', 'term_taxonomy') . ' tt JOIN ' . $this->t('l', 'terms') . ' t ON t.term_id = tt.term_id WHERE tt.taxonomy = ' . $this->db->quote($r['taxonomy']) . ' AND t.slug = ' . $this->db->quote($r['slug']) . ' LIMIT 1');
            if ($found !== null) {
                $this->terms[(int) $r['term_taxonomy_id']] = (int) $found;
                continue;
            }
            $this->db->exec('INSERT INTO ' . $this->t('l', 'terms') . ' (name, slug, term_group) VALUES (' . $this->db->quote($r['name']) . ', ' . $this->db->quote($r['slug']) . ', ' . (int) $r['term_group'] . ')');
            $termId = (int) $this->db->value('SELECT LAST_INSERT_ID()');
            $this->db->exec('INSERT INTO ' . $this->t('l', 'term_taxonomy') . ' (term_id, taxonomy, description, parent, count) VALUES (' . $termId . ', ' . $this->db->quote($r['taxonomy']) . ', ' . $this->db->quote($r['description']) . ', 0, 0)');
            $ttId = (int) $this->db->value('SELECT LAST_INSERT_ID()');
            $this->terms[(int) $r['term_taxonomy_id']] = $ttId;
            $newTerm[$ttId] = (int) $r['parent']; // package term_id of the parent, translated below
            $stats['terms_added']++;
            if (in_array($this->s . 'termmeta', $this->db->tables($this->s), true)) {
                $this->db->exec('INSERT INTO ' . $this->t('l', 'termmeta') . ' (term_id, meta_key, meta_value) SELECT ' . $termId . ', meta_key, meta_value FROM ' . $this->t('s', 'termmeta') . ' WHERE term_id = ' . (int) $r['term_id']);
            }
        }
        $byTerm = [];
        foreach ($rows as $r) {
            $byTerm[(int) $r['term_id']] = (int) $r['term_taxonomy_id'];
        }
        foreach ($newTerm as $ttId => $parentTerm) {
            if ($parentTerm > 0 && isset($byTerm[$parentTerm], $this->terms[$byTerm[$parentTerm]])) {
                $this->db->exec('UPDATE ' . $this->t('l', 'term_taxonomy') . ' SET parent = (SELECT term_id FROM (SELECT term_id FROM ' . $this->t('l', 'term_taxonomy') . ' WHERE term_taxonomy_id = ' . $this->terms[$byTerm[$parentTerm]] . ') x) WHERE term_taxonomy_id = ' . (int) $ttId);
            } elseif ($parentTerm > 0) {
                $this->flag('term', $ttId, 'parent term ' . $parentTerm . ' is not in the package');
            }
        }
    }

    // ------------------------------------------------------------- posts

    /** @return int[] package IDs of posts that were added or overwritten (their meta, terms and comments follow) */
    private function posts(array &$stats)
    {
        $cols = $this->common('posts');
        $list = implode(', ', array_map(['SiteShifter\Database\Connection', 'ident'], $cols));
        $mode = $this->policy->get('posts');
        $changed = [];
        foreach ($this->db->rows('SELECT ID, post_type, post_name, guid FROM ' . $this->t('s', 'posts') . ' ORDER BY ID') as $p) {
            $sid = (int) $p['ID'];
            $clash = $p['post_type'] === 'attachment'
                ? $this->db->value('SELECT ID FROM ' . $this->t('l', 'posts') . ' WHERE post_type = \'attachment\' AND guid = ' . $this->db->quote($p['guid']) . ' LIMIT 1')
                : ($p['post_name'] === '' ? null : $this->db->value('SELECT ID FROM ' . $this->t('l', 'posts') . ' WHERE post_type = ' . $this->db->quote($p['post_type']) . ' AND post_name = ' . $this->db->quote($p['post_name']) . ' LIMIT 1'));
            if ($clash === null) {
                $this->db->exec('INSERT INTO ' . $this->t('l', 'posts') . " ({$list}) SELECT {$list} FROM " . $this->t('s', 'posts') . ' WHERE ID = ' . $sid);
                $this->posts[$sid] = (int) $this->db->value('SELECT LAST_INSERT_ID()');
                $stats['posts_added']++;
                $changed[] = $sid;
            } elseif ($mode === 'skip') {
                $this->posts[$sid] = (int) $clash;
                $stats['posts_skipped']++;
            } elseif ($mode === 'overwrite') {
                $set = implode(', ', array_map(function ($c) {
                    $i = Connection::ident($c);

                    return "l.{$i} = s.{$i}";
                }, $cols));
                $this->db->exec('UPDATE ' . $this->t('l', 'posts') . ' l JOIN ' . $this->t('s', 'posts') . " s ON s.ID = {$sid} SET {$set} WHERE l.ID = " . (int) $clash);
                $this->posts[$sid] = (int) $clash;
                $this->db->exec('DELETE FROM ' . $this->t('l', 'postmeta') . ' WHERE post_id = ' . (int) $clash);
                $this->db->exec('DELETE FROM ' . $this->t('l', 'term_relationships') . ' WHERE object_id = ' . (int) $clash);
                $stats['posts_overwritten']++;
                $changed[] = $sid;
            } else { // rename: keep both, the new one gets a free slug
                $this->db->exec('INSERT INTO ' . $this->t('l', 'posts') . " ({$list}) SELECT {$list} FROM " . $this->t('s', 'posts') . ' WHERE ID = ' . $sid);
                $new = (int) $this->db->value('SELECT LAST_INSERT_ID()');
                $this->db->exec('UPDATE ' . $this->t('l', 'posts') . ' SET post_name = ' . $this->db->quote($this->freeSlug($p['post_type'], $p['post_name'])) . ' WHERE ID = ' . $new);
                $this->posts[$sid] = $new;
                $stats['posts_renamed']++;
                $changed[] = $sid;
            }
        }

        return $changed;
    }

    private function freeSlug($type, $slug)
    {
        for ($i = 2; ; $i++) {
            $try = substr($slug, 0, 190) . '-' . $i;
            if ($this->db->value('SELECT 1 FROM ' . $this->t('l', 'posts') . ' WHERE post_type = ' . $this->db->quote($type) . ' AND post_name = ' . $this->db->quote($try) . ' LIMIT 1') === null) {
                return $try;
            }
        }
    }

    /** Parent, author and meta that holds a post ID. */
    private function fixReferences(array $changed)
    {
        $metaCols = ['meta_key', 'meta_value'];
        foreach ($changed as $sid) {
            $id = $this->posts[$sid];
            $row = $this->db->rows('SELECT post_parent, post_author FROM ' . $this->t('l', 'posts') . ' WHERE ID = ' . $id)[0];
            $parent = (int) $row['post_parent'];
            $newParent = $parent === 0 ? 0 : ($this->posts[$parent] ?? 0);
            if ($parent !== 0 && !isset($this->posts[$parent])) {
                $this->flag('post', $sid, 'parent ' . $parent . ' is not in the package, cleared');
            }
            $author = (int) $row['post_author'];
            $newAuthor = $this->users[$author] ?? 0;
            if ($newAuthor === 0 && $author !== 0) {
                $this->flag('post', $sid, 'author ' . $author . ' was not imported, set to none');
            }
            $this->db->exec('UPDATE ' . $this->t('l', 'posts') . " SET post_parent = {$newParent}, post_author = {$newAuthor} WHERE ID = {$id}");
            $this->db->exec('INSERT INTO ' . $this->t('l', 'postmeta') . ' (post_id, meta_key, meta_value) SELECT ' . $id . ', meta_key, meta_value FROM ' . $this->t('s', 'postmeta') . ' WHERE post_id = ' . (int) $sid);
            foreach (self::ID_META as $key) {
                $old = $this->db->value('SELECT meta_value FROM ' . $this->t('l', 'postmeta') . ' WHERE post_id = ' . $id . ' AND meta_key = ' . $this->db->quote($key) . ' LIMIT 1');
                if ($old === null) {
                    continue;
                }
                $new = $this->posts[(int) $old] ?? 0;
                if ($new === 0) {
                    $this->flag('post', $sid, $key . ' points at ' . $old . ' which is not in the package, removed');
                    $this->db->exec('DELETE FROM ' . $this->t('l', 'postmeta') . ' WHERE post_id = ' . $id . ' AND meta_key = ' . $this->db->quote($key));
                } else {
                    $this->db->exec('UPDATE ' . $this->t('l', 'postmeta') . ' SET meta_value = ' . $new . ' WHERE post_id = ' . $id . ' AND meta_key = ' . $this->db->quote($key));
                }
            }
            if ($this->db->value('SELECT 1 FROM ' . $this->t('l', 'postmeta') . ' WHERE post_id = ' . $id . " AND meta_key IN ('_elementor_data','_wp_attachment_metadata_ids') LIMIT 1") !== null) {
                $this->flag('post', $sid, 'Elementor data may hold IDs of other posts or media that are not translated; check this page');
            }
        }
        unset($metaCols);
    }

    private function relationships(array $changed)
    {
        if (!in_array($this->s . 'term_relationships', $this->db->tables($this->s), true)) {
            return;
        }
        foreach ($changed as $sid) {
            foreach ($this->db->rows('SELECT term_taxonomy_id, term_order FROM ' . $this->t('s', 'term_relationships') . ' WHERE object_id = ' . (int) $sid) as $r) {
                $tt = $this->terms[(int) $r['term_taxonomy_id']] ?? null;
                if ($tt === null) {
                    $this->flag('post', $sid, 'term ' . $r['term_taxonomy_id'] . ' is not in the package, skipped');
                    continue;
                }
                $this->db->exec('INSERT IGNORE INTO ' . $this->t('l', 'term_relationships') . ' (object_id, term_taxonomy_id, term_order) VALUES (' . $this->posts[$sid] . ', ' . $tt . ', ' . (int) $r['term_order'] . ')');
            }
        }
        $this->db->exec('UPDATE ' . $this->t('l', 'term_taxonomy') . ' tt SET count = (SELECT COUNT(*) FROM ' . $this->t('l', 'term_relationships') . ' r WHERE r.term_taxonomy_id = tt.term_taxonomy_id)');
    }

    // ------------------------------------------------------------- comments

    private function comments(array $changed, array &$stats)
    {
        if (!in_array($this->s . 'comments', $this->db->tables($this->s), true) || $changed === []) {
            return;
        }
        $cols = $this->common('comments');
        $list = implode(', ', array_map(['SiteShifter\Database\Connection', 'ident'], $cols));
        $hasMeta = in_array($this->s . 'commentmeta', $this->db->tables($this->s), true);
        foreach ($changed as $sid) {
            if ($this->db->value('SELECT 1 FROM ' . $this->t('l', 'comments') . ' WHERE comment_post_ID = ' . $this->posts[$sid] . ' LIMIT 1') !== null && $this->policy->get('posts') === 'overwrite') {
                $this->db->exec('DELETE FROM ' . $this->t('l', 'comments') . ' WHERE comment_post_ID = ' . $this->posts[$sid]);
            }
            foreach ($this->db->rows('SELECT comment_ID, comment_parent, user_id FROM ' . $this->t('s', 'comments') . ' WHERE comment_post_ID = ' . (int) $sid . ' ORDER BY comment_ID') as $c) {
                $this->db->exec('INSERT INTO ' . $this->t('l', 'comments') . " ({$list}) SELECT {$list} FROM " . $this->t('s', 'comments') . ' WHERE comment_ID = ' . (int) $c['comment_ID']);
                $new = (int) $this->db->value('SELECT LAST_INSERT_ID()');
                $this->comments[(int) $c['comment_ID']] = $new;
                $parent = (int) $c['comment_parent'];
                $user = (int) $c['user_id'];
                $newUser = $user === 0 ? 0 : ($this->users[$user] ?? 0);
                $newParent = $parent === 0 ? 0 : ($this->comments[$parent] ?? 0);
                if ($parent !== 0 && $newParent === 0) {
                    $this->flag('comment', $c['comment_ID'], 'parent comment ' . $parent . ' not found, cleared');
                }
                $this->db->exec('UPDATE ' . $this->t('l', 'comments') . ' SET comment_post_ID = ' . $this->posts[$sid] . ", comment_parent = {$newParent}, user_id = {$newUser} WHERE comment_ID = {$new}");
                if ($hasMeta) {
                    $this->db->exec('INSERT INTO ' . $this->t('l', 'commentmeta') . ' (comment_id, meta_key, meta_value) SELECT ' . $new . ', meta_key, meta_value FROM ' . $this->t('s', 'commentmeta') . ' WHERE comment_id = ' . (int) $c['comment_ID']);
                }
                $stats['comments_added']++;
            }
            $this->db->exec('UPDATE ' . $this->t('l', 'posts') . ' SET comment_count = (SELECT COUNT(*) FROM ' . $this->t('l', 'comments') . ' WHERE comment_post_ID = ' . $this->posts[$sid] . " AND comment_approved = '1') WHERE ID = " . $this->posts[$sid]);
        }
    }
}
