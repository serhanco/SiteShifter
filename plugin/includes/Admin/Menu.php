<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Admin;

defined('ABSPATH') || exit;

class Menu
{
    const SLUG = 'siteshifter';
    const CAPABILITY = 'manage_options';

    private static function profileRadios()
    {
        $profiles = [
            'full' => __('Everything (the whole site)', 'siteshifter'),
            'structure' => __('Structure only: themes, plugins, settings, menus and templates, no content', 'siteshifter'),
            'settings' => __('Settings only (the options table, no files)', 'siteshifter'),
            'content' => __('Content only: posts, pages, media, comments, users, no themes, plugins or settings', 'siteshifter'),
            'custom' => __('Custom: choose content types and parts', 'siteshifter'),
        ];
        $out = '';
        foreach ($profiles as $value => $label) {
            $out .= '<label><input type="radio" name="ss-profile" value="' . esc_attr($value) . '"' . ($value === 'full' ? ' checked' : '') . '> ' . esc_html($label) . '</label><br>';
        }

        return $out;
    }

    private static function customChoices()
    {
        $out = '<p><strong>' . esc_html__('Content types', 'siteshifter') . '</strong></p>';
        $skip = ['attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_font_family', 'wp_font_face'];
        foreach (get_post_types([], 'objects') as $type) {
            if (in_array($type->name, $skip, true) || (empty($type->public) && empty($type->show_ui))) {
                continue;
            }
            $counts = wp_count_posts($type->name);
            $n = 0;
            foreach ((array) $counts as $count) {
                $n += (int) $count;
            }
            $out .= '<label><input type="checkbox" class="ss-type" value="' . esc_attr($type->name) . '" checked> ' . esc_html($type->labels->name) . ' <span class="description">(' . (int) $n . ')</span></label><br>';
        }
        $parts = [
            'media' => __('Media library (uploads folder and attachments)', 'siteshifter'),
            'comments' => __('Comments', 'siteshifter'),
            'users' => __('Users', 'siteshifter'),
            'settings' => __('Settings (options table)', 'siteshifter'),
            'themes' => __('Themes', 'siteshifter'),
            'plugins' => __('Plugins', 'siteshifter'),
            'other_tables' => __('Plugin data tables', 'siteshifter'),
            'revisions' => __('Post revisions', 'siteshifter'),
        ];
        $out .= '<p><strong>' . esc_html__('Parts', 'siteshifter') . '</strong></p>';
        foreach ($parts as $key => $label) {
            $out .= '<label><input type="checkbox" class="ss-part" data-part="' . esc_attr($key) . '" checked> ' . esc_html($label) . '</label><br>';
        }

        return $out;
    }

    public function register()
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function addMenu()
    {
        add_management_page(
            __('SiteShifter', 'siteshifter'),
            __('SiteShifter', 'siteshifter'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function assets($hook)
    {
        if ($hook !== 'tools_page_' . self::SLUG) {
            return;
        }
        wp_enqueue_script('siteshifter-jobs', SITESHIFTER_URL . 'assets/job-runner.js', [], SITESHIFTER_VERSION, true);
        wp_localize_script('siteshifter-jobs', 'siteshifterJobs', [
            'restUrl' => esc_url_raw(rest_url(\SiteShifter\Plugin\Rest\Routes::NAMESPACE_ . '/')),
            'loginUrl' => esc_url_raw(wp_login_url()),
            'restNonce' => wp_create_nonce('wp_rest'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'ajaxAction' => \SiteShifter\Plugin\Rest\Routes::AJAX_ACTION,
            'uploadAction' => \SiteShifter\Plugin\Rest\Routes::UPLOAD_ACTION,
            'chunkBytes' => max(262144, (int) floor(min((int) wp_max_upload_size(), 8 * 1048576) * 0.9)),
            'pullAction' => \SiteShifter\Plugin\Rest\Routes::PULL_ACTION,
            'downloadAction' => \SiteShifter\Plugin\Rest\Routes::DOWNLOAD_ACTION,
            'ajaxNonce' => wp_create_nonce(\SiteShifter\Plugin\Rest\Routes::NONCE),
            'i18n' => [
                'retrying' => __('Connection problem, trying again (%d)…', 'siteshifter'),
                'badReply' => __('The server sent an unexpected reply (a firewall or cache may be in the way).', 'siteshifter'),
                'done' => __('Finished.', 'siteshifter'),
                'paused' => __('Paused after repeated errors. You can resume from where it stopped.', 'siteshifter'),
                'failed' => __('Could not finish:', 'siteshifter'),
                'download' => __('Download package', 'siteshifter'),
                'pullLink' => __('Create a link for another site', 'siteshifter'),
                'pullNote' => __('Paste this link into SiteShifter > Import > "Fetch from another site" on the other site. It works for 30 minutes and anyone who has it can download the package, so share it only there.', 'siteshifter'),
                'fetchEmpty' => __('Paste the link first.', 'siteshifter'),
                'deletePackage' => __('Delete package from this server', 'siteshifter'),
                /* translators: 1: size, 2: number of files, 3: number of database tables */
                'packageReady' => __('The package is ready and checked: %1$s, %2$s files, %3$s database tables. The link works for 15 minutes; create a new one if it expires.', 'siteshifter'),
                'packageNote' => __('The package contains your database, including user password hashes and wp-config.php. Keep it private and delete it from this server after you have downloaded it.', 'siteshifter'),
                'deleted' => __('Deleted.', 'siteshifter'),
                'problems' => ProblemText::all(),
                'technical' => __('Technical detail:', 'siteshifter'),
                'measuring' => __('Measuring the site (folders, database, free disk space)…', 'siteshifter'),
                'useNoMedia' => __('Use "without media" and copy uploads myself', 'siteshifter'),
                'continueAnyway' => __('Continue anyway', 'siteshifter'),
                'mediaOffNote' => __('Media is now switched off in the custom options. Copy wp-content/uploads to the new site by hand, then click "Check and create package" again.', 'siteshifter'),
                'noIncoming' => __('No packages have been uploaded yet.', 'siteshifter'),
                'check' => __('Check this package', 'siteshifter'),
                'chooseFile' => __('Choose a .zip file first.', 'siteshifter'),
                'uploading' => __('Uploading…', 'siteshifter'),
                'uploaded' => __('Uploaded and verified.', 'siteshifter'),
                'summary' => __('Package from %1$s, %2$s, WordPress %3$s, %4$s tables, scope: %5$s', 'siteshifter'),
                'noPackages' => __('There are no packages on this server.', 'siteshifter'),
                'colDate' => __('Created', 'siteshifter'),
                'colSize' => __('Size', 'siteshifter'),
                'colScope' => __('Scope', 'siteshifter'),
                'colSource' => __('Source address', 'siteshifter'),
                'colStatus' => __('Check', 'siteshifter'),
                'checkedOk' => __('Passed', 'siteshifter'),
                'checkedBad' => __('Failed', 'siteshifter'),
                'manualUploads' => __('uploads copied by hand', 'siteshifter'),
                'deleteSelected' => __('Delete selected', 'siteshifter'),
                'confirmDelete' => __('Delete the selected packages from this server? This cannot be undone.', 'siteshifter'),
                'saved' => __('Saved.', 'siteshifter'),
                'profiles' => [
                    'full' => __('Everything', 'siteshifter'),
                    'structure' => __('Structure only', 'siteshifter'),
                    'settings' => __('Settings only', 'siteshifter'),
                    'content' => __('Content only', 'siteshifter'),
                    'custom' => __('Custom', 'siteshifter'),
                ],
                'confirmReplace' => __('I understand: this replaces the database, the themes, the plugins and the uploads of this site with the package. The current data is kept so I can undo it.', 'siteshifter'),
                'merge' => [
                    'replace' => __('Replace: the site becomes exactly the package (the current data is kept for undo).', 'siteshifter'),
                    'merge' => __('Merge: keep what is on this site, add what is new, and decide conflicts below.', 'siteshifter'),
                    'note' => __('Merge never deletes anything and never changes the site address, the active plugins or the roles. Posts, comments and categories are added next to yours and their links (parent, author, featured image, categories) are translated; a link that cannot be translated is cleared and listed after the import. IDs stored inside page-builder data are not translated.', 'siteshifter'),
                    'labels' => [
                        'themes' => __('Same theme in both', 'siteshifter'),
                        'plugins' => __('Same plugin in both', 'siteshifter'),
                        'media' => __('Same media file in both', 'siteshifter'),
                        'other_files' => __('Same other file in both', 'siteshifter'),
                        'settings' => __('Same setting in both', 'siteshifter'),
                        'users' => __('Same user (e-mail or login) in both', 'siteshifter'),
                        'content' => __('Posts, comments and categories of the package', 'siteshifter'),
                        'posts' => __('Same post or page (type and slug) in both', 'siteshifter'),
                    ],
                    'choices' => \SiteShifter\Plugin\Import\MergePolicy::CHOICES,
                    'names' => [
                        'keep_newer' => __('use the newer version', 'siteshifter'),
                        'overwrite' => __('overwrite with the package', 'siteshifter'),
                        'skip' => __('keep this site\'s', 'siteshifter'),
                        'rename' => __('keep both (new name)', 'siteshifter'),
                        'keep' => __('keep this site\'s', 'siteshifter'),
                        'map' => __('use the existing user', 'siteshifter'),
                        'merge' => __('add them', 'siteshifter'),
                    ],
                ],
                'mergeFlags' => __('Links that could not be translated (cleared, please check):', 'siteshifter'),
                'mergeResult' => __('Merge result (files by action, then database counts):', 'siteshifter'),
                'importNow' => __('Import and replace this site', 'siteshifter'),
                'importDone' => __('The import is finished and the site now runs the imported data.', 'siteshifter'),
                /* translators: %d: number of places */
                'importLeftovers' => __('The old address still appears in %d places.', 'siteshifter'),
                /* translators: %d: number of tables */
                'importMismatch' => __('%d tables have a different number of rows than in the package.', 'siteshifter'),
                'importLogin' => __('The users of the package are now this site\'s users, so your login session has ended. Log in again at:', 'siteshifter'),
                'appliedImports' => __('Imports on this site', 'siteshifter'),
                'importStates' => [
                    'live' => __('Applied (can be undone)', 'siteshifter'),
                    'undone' => __('Undone', 'siteshifter'),
                    'discarded' => __('Backup deleted', 'siteshifter'),
                    'swapping' => __('Interrupted while switching', 'siteshifter'),
                    'failed' => __('Failed, nothing was changed', 'siteshifter'),
                ],
                'undoImport' => __('Undo: put the previous site back', 'siteshifter'),
                'discardBackup' => __('Delete the backup', 'siteshifter'),
                'confirmUndo' => __('Put the site back as it was before this import? Changes made since the import are lost.', 'siteshifter'),
                'confirmDiscard' => __('Delete the backup of the previous site? After this the import can no longer be undone.', 'siteshifter'),
                'checking' => __('Checking…', 'siteshifter'),
                /* translators: %d: number of database rows */
                'changed' => __('Done. %d database rows were updated.', 'siteshifter'),
                /* translators: %d: number of places */
                'leftovers' => __('The old address still appears in %d places (see the list in Checks and history after a reload).', 'siteshifter'),
                'noLeftovers' => __('The old address no longer appears anywhere in the database.', 'siteshifter'),
                'login' => __('Your login session ends with the address change. Log in again at:', 'siteshifter'),
                'rollback' => __('Undo: restore the database from the backup taken before the change', 'siteshifter'),
            ],
        ]);
    }

    /** @return array<string,string> tab slug => label */
    public function tabs()
    {
        return [
            'export' => __('Export', 'siteshifter'),
            'packages' => __('Packages', 'siteshifter'),
            'import' => __('Import', 'siteshifter'),
            'address' => __('Change address', 'siteshifter'),
            'status' => __('Checks and history', 'siteshifter'),
        ];
    }

    public function render()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to open this page.', 'siteshifter'), 403);
        }
        $tabs = $this->tabs();
        $current = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'export'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($tabs[$current])) {
            $current = 'export';
        }

        echo '<div class="wrap"><h1>' . esc_html__('SiteShifter', 'siteshifter') . '</h1>';
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $url = add_query_arg(['page' => self::SLUG, 'tab' => $slug], admin_url('tools.php'));
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url($url),
                $slug === $current ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</nav>';
        if ($current === 'status') {
            echo '<h2>' . esc_html__('Test this server', 'siteshifter') . '</h2><p>'
                . esc_html__('Writes and checks a 32 MB test file in small steps, the way a real export would. It shows whether long jobs can run on this host.', 'siteshifter')
                . '</p><p><button class="button button-primary" data-ss-host-test>' . esc_html__('Run server test', 'siteshifter') . '</button></p>'
                . '<div id="ss-host-test-progress"></div>';
        } elseif ($current === 'export') {
            echo '<p>' . esc_html__('Packs this site (files and database) into one zip you can move to another WordPress. The package is checked before you can download it.', 'siteshifter') . '</p>'
                . '<form id="ss-export" onsubmit="return false"><table class="form-table" role="presentation"><tbody>'
                . '<tr><th>' . esc_html__('What to include', 'siteshifter') . '</th><td>'
                . self::profileRadios()
                . '<div id="ss-custom" style="display:none;margin:8px 0 0 24px">'
                . self::customChoices()
                . '</div></td></tr>'
                . '<tr><th>' . esc_html__('Files', 'siteshifter') . '</th><td>'
                . '<label><input type="radio" name="ss-scope" value="root" checked> ' . esc_html__('The whole site (WordPress files, themes, plugins, uploads)', 'siteshifter') . '</label><br>'
                . '<label><input type="radio" name="ss-scope" value="wp-content"> ' . esc_html__('Only wp-content (themes, plugins, uploads)', 'siteshifter') . '</label><br>'
                . '<label><input type="checkbox" id="ss-all-tables"> ' . esc_html__('Also include database tables that do not use this site\'s table prefix', 'siteshifter') . '</label>'
                . '</td></tr><tr><th><label for="ss-threshold">' . esc_html__('Suggest copying uploads by hand above', 'siteshifter') . '</label></th><td>'
                . '<input type="number" id="ss-threshold" min="0.1" step="0.1" value="2" class="small-text"> ' . esc_html__('GB', 'siteshifter')
                . '</td></tr></tbody></table><p><button class="button button-primary" id="ss-export-start">' . esc_html__('Check and create package', 'siteshifter') . '</button></p></form>'
                . '<div id="ss-export-report"></div><div id="ss-export-choice"></div><div id="ss-export-progress"></div><div id="ss-export-result"></div>';
        } elseif ($current === 'packages') {
            echo '<p>' . esc_html__('Packages made on this server. They hold your database and wp-config.php, so delete them when you no longer need them.', 'siteshifter') . '</p>'
                . '<div id="ss-packages"></div>'
                . '<h2>' . esc_html__('Keep packages', 'siteshifter') . '</h2>'
                . '<form id="ss-retention" onsubmit="return false"><p>'
                . '<label><input type="checkbox" id="ss-ret-on"> ' . esc_html__('Delete old packages automatically (once a day)', 'siteshifter') . '</label></p><p>'
                . '<label>' . esc_html__('Keep at most', 'siteshifter') . ' <input type="number" id="ss-ret-count" min="0" max="1000" class="small-text"> ' . esc_html__('packages', 'siteshifter') . '</label> '
                . '<label>' . esc_html__('and none older than', 'siteshifter') . ' <input type="number" id="ss-ret-days" min="0" max="3650" class="small-text"> ' . esc_html__('days (0 = no limit)', 'siteshifter') . '</label></p>'
                . '<p><button class="button" id="ss-ret-save">' . esc_html__('Save', 'siteshifter') . '</button> <span id="ss-ret-note"></span></p></form>';
        } elseif ($current === 'address') {
            $home = untrailingslashit((string) get_option('home'));
            echo '<p>' . esc_html__('Changes the site address in the database (including serialized data, Elementor, ACF and WPML values). A full database backup is taken first so you can undo it.', 'siteshifter') . '</p>'
                . '<form id="ss-address" onsubmit="return false"><table class="form-table" role="presentation"><tbody>'
                . '<tr><th><label for="ss-old-url">' . esc_html__('Current address', 'siteshifter') . '</label></th><td><input type="url" id="ss-old-url" class="regular-text" value="' . esc_attr($home) . '"></td></tr>'
                . '<tr><th><label for="ss-new-url">' . esc_html__('New address', 'siteshifter') . '</label></th><td><input type="url" id="ss-new-url" class="regular-text" placeholder="https://"></td></tr>'
                . '</tbody></table><p><button class="button" id="ss-address-check">' . esc_html__('Run checks', 'siteshifter') . '</button> '
                . '<button class="button button-primary" id="ss-address-start">' . esc_html__('Check and change address', 'siteshifter') . '</button></p></form>'
                . '<div id="ss-address-report"></div><div id="ss-address-progress"></div><div id="ss-address-result"></div>';
        } elseif ($current === 'import') {
            echo '<p>' . esc_html__('Upload a package made by SiteShifter (or put it in the incoming folder by FTP for very large sites), then check it. The check changes nothing: it shows what would be replaced, what is incompatible and what you must do by hand.', 'siteshifter') . '</p>'
                . '<p><input type="file" id="ss-file" accept=".zip" aria-label="' . esc_attr__('Upload package', 'siteshifter') . '"> <button class="button button-primary" id="ss-upload">' . esc_html__('Upload package', 'siteshifter') . '</button></p>'
                . '<div id="ss-upload-progress"></div>'
                . '<h2>' . esc_html__('Fetch from another site', 'siteshifter') . '</h2>'
                . '<p>' . esc_html__('Faster than downloading and uploading: this server fetches the package directly from the other site, in pieces, using the link made on its Export screen.', 'siteshifter') . '</p>'
                . '<p><input type="url" id="ss-fetch-url" class="large-text code" placeholder="https://" aria-label="' . esc_attr__('Link from the other site', 'siteshifter') . '"></p>'
                . '<p><button class="button button-primary" id="ss-fetch">' . esc_html__('Fetch package', 'siteshifter') . '</button></p><div id="ss-fetch-progress"></div>'
                . '<h2>' . esc_html__('Packages ready to import', 'siteshifter') . '</h2><div id="ss-incoming"></div>'
                . '<div id="ss-import-report" aria-live="polite"></div><div id="ss-imports" aria-live="polite"></div>'
                . '<p class="description">' . esc_html(sprintf(
                    /* translators: %s: folder path */
                    __('FTP folder: %s', 'siteshifter'),
                    'wp-content/siteshifter-work/incoming/'
                )) . '</p>';
        } else {
            echo '<p>' . esc_html__('This screen is coming in a later step.', 'siteshifter') . '</p>';
        }
        echo '<p class="description" style="margin-top:2em">' . self::signature() . '</p></div>';
    }

    /** Subtle, escaped credit line shown on every SiteShifter screen. */
    public static function signature()
    {
        return sprintf(
            /* translators: %s: link to the author's website */
            esc_html__('by %s', 'siteshifter'),
            '<a href="https://serhandemirel.com" target="_blank" rel="noopener">Serhan Demirel</a>'
        );
    }
}
