<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Admin;

use SiteShifter\Package\Scope;

defined('ABSPATH') || exit;

/**
 * Turns the core's scope warning codes into translated report rows.
 */
class ScopeText
{
    /**
     * @return array<int,array{level:string,title:string,message:string,fix:string}>
     */
    public static function rows(Scope $scope)
    {
        $rows = [];
        foreach ($scope->warnings() as $w) {
            $text = self::text($w['code']);
            if ($text === null) {
                continue;
            }
            $rows[] = ['level' => $w['level'] === 'warning' ? 'warn' : 'info', 'title' => $text[0], 'message' => $text[1], 'fix' => $text[2]];
        }

        return $rows;
    }

    private static function text($code)
    {
        switch ($code) {
            case 'media_excluded':
                return [
                    __('Uploads are not included', 'siteshifter'),
                    __('Posts that use images or files from the media library will show them as missing until the uploads folder is on the new site.', 'siteshifter'),
                    __('Copy wp-content/uploads to the new site yourself (SFTP or rsync), then run the import check again.', 'siteshifter'),
                ];
            case 'users_excluded':
                return [
                    __('Users are not included', 'siteshifter'),
                    __('Posts keep their author number, which may not exist on the new site.', 'siteshifter'),
                    __('Choose an existing user on the new site to own the imported content.', 'siteshifter'),
                ];
            case 'comments_excluded':
                return [__('Comments are not included', 'siteshifter'), __('The new site will have no comments from this one.', 'siteshifter'), ''];
            case 'settings_excluded':
                return [__('Settings are not included', 'siteshifter'), __('The new site keeps its own settings, menus and widgets.', 'siteshifter'), ''];
            case 'code_excluded':
                return [
                    __('Themes or plugins are not included', 'siteshifter'),
                    __('Content that needs them may look wrong or break on the new site.', 'siteshifter'),
                    __('Make sure the same themes and plugins are installed and active on the new site.', 'siteshifter'),
                ];
            case 'types_limited':
                return [__('Only the chosen content types are included', 'siteshifter'), __('Other post types, and their custom fields and category links, are left out.', 'siteshifter'), ''];
            case 'plugin_tables_excluded':
                return [
                    __('Plugin data tables are not included', 'siteshifter'),
                    __('Data that plugins keep in their own tables (forms, orders, bookings) is not packed.', 'siteshifter'),
                    __('Include them if you need that data on the new site.', 'siteshifter'),
                ];
        }

        return null;
    }
}
