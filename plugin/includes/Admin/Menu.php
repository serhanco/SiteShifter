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
        echo '<p>' . esc_html__('This screen is coming in a later step.', 'siteshifter') . '</p>';
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
