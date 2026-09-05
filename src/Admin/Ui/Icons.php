<?php

declare(strict_types=1);

namespace Gereq\Admin\Ui;

/**
 * Google Material Symbols (Outlined) — ligature names.
 * @see https://fonts.google.com/icons
 */
final class Icons
{
    public function svg(string $name, string $class = ''): string
    {
        $glyph = $this->glyphs()[$name] ?? 'circle';
        $classes = trim('material-symbols-outlined g-icon ' . $class);

        return '<span class="' . htmlspecialchars($classes, ENT_QUOTES)
            . '" aria-hidden="true">' . htmlspecialchars($glyph, ENT_QUOTES) . '</span>';
    }

    /** @return array<string, string> */
    private function glyphs(): array
    {
        return [
            'home' => 'home',
            'users' => 'group',
            'user' => 'person',
            'modules' => 'grid_view',
            'settings' => 'settings',
            'shield' => 'shield',
            'activity' => 'monitoring',
            'search' => 'search',
            'plus' => 'add',
            'logout' => 'logout',
            'chevron-right' => 'chevron_right',
            'chevron-left' => 'chevron_left',
            'chevron-down' => 'expand_more',
            'chevron-up' => 'expand_less',
            'trash' => 'delete',
            'dots' => 'more_horiz',
            'dot' => 'fiber_manual_record',
            'bell' => 'notifications',
            'sun' => 'light_mode',
            'moon' => 'dark_mode',
            'building' => 'apartment',
            'mail' => 'mail',
            'phone' => 'call',
            'check' => 'check',
            'x' => 'close',
            'close' => 'close',
            'filter' => 'filter_list',
            'filters' => 'tune',
            'alert' => 'warning',
            'panel' => 'view_sidebar',
            'database' => 'database',
            'key' => 'key',
            'file' => 'description',
            'package' => 'inventory_2',
            'cart' => 'shopping_cart',
            'bag' => 'shopping_bag',
            'truck' => 'local_shipping',
            'credit-card' => 'credit_card',
            'tag' => 'sell',
            'undo' => 'undo',
            'redo' => 'redo',
            'refresh' => 'sync',
            'star' => 'star',
            'gift' => 'card_giftcard',
            'layers' => 'layers',
            'rss' => 'rss_feed',
            'warehouse' => 'warehouse',
            'coins' => 'monetization_on',
            'image' => 'image',
            'layout' => 'dashboard',
            'calendar' => 'calendar_month',
            'briefcase' => 'work',
            'zap' => 'bolt',
            'receipt' => 'receipt_long',
            'clipboard' => 'content_paste',
            'folder' => 'folder',
            'book' => 'menu_book',
            'link' => 'link',
            'palette' => 'palette',
            'language' => 'language',
            'storefront' => 'storefront',
            'format-bold' => 'format_bold',
            'format-italic' => 'format_italic',
            'format-underline' => 'format_underlined',
            'format-strike' => 'strikethrough_s',
            'format-align-left' => 'format_align_left',
            'format-align-center' => 'format_align_center',
            'format-align-right' => 'format_align_right',
            'format-align-justify' => 'format_align_justify',
            'format-list-bulleted' => 'format_list_bulleted',
            'format-list-numbered' => 'format_list_numbered',
            'format-quote' => 'format_quote',
            'format-clear' => 'format_clear',
            'horizontal-rule' => 'horizontal_rule',
        ];
    }
}
