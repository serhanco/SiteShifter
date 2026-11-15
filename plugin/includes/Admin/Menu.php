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
            'restNonce' => wp_create_nonce('wp_rest'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'ajaxAction' => \SiteShifter\Plugin\Rest\Routes::AJAX_ACTION,
            'ajaxNonce' => wp_create_nonce(\SiteShifter\Plugin\Rest\Routes::NONCE),
            'i18n' => [
                'retrying' => __('Connection problem, trying again (%d)…', 'siteshifter'),
                'badReply' => __('The server sent an unexpected reply (a firewall or cache may be in the way).', 'siteshifter'),
                'done' => __('Finished.', 'siteshifter'),
                'paused' => __('Paused after repeated errors. You can resume from where it stopped.', 'siteshifter'),
                'failed' => __('Could not finish:', 'siteshifter'),
            ],
        ]);
    }

    /** @return array<string,string> tab slug => label */
    public function tabs()
    {
        return [
            'export' => __('Export', 'siteshifter'),
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
