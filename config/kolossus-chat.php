<?php

/*
 * KolossusAI Chat settings.
 *
 * `php artisan kolossus:install` writes the Host URL and widget token into your .env
 * file (free-laravel, the free FAQ assistant, until you put your own token there).
 * Everything else is optional. Empty look settings (theme, colour,
 * layout, position, offsets, size) mean "use the widget's own setting" from Kolossus.
 * Invalid values are ignored and logged as warnings.
 *
 * To change this file, publish it first:
 *     php artisan vendor:publish --tag=kolossus-chat-config
 */

return [

    // Your Kolossus server: https only (plain http:// only for localhost while testing).
    'host_url' => env('KOLOSSUS_CHAT_HOST_URL', 'https://engine.kolossus.ai'),

    // The PUBLIC widget token (wt_...), from your widget in the Kolossus dashboard.
    // Not set (or empty): the free FAQ assistant, so the chat works on a server whose
    // environment has no token line. (A plain string, not ChatConfig::FREE_TOKEN: a
    // published copy of this file must still load after the package is removed.)
    'widget_token' => env('KOLOSSUS_CHAT_WIDGET_TOKEN') ?: 'free-laravel',

    // Optional widget secret (ws_...). It stays on your server: it only signs the email
    // of a logged-in user so the chat knows who they are. Never put it anywhere else.
    'widget_secret' => env('KOLOSSUS_CHAT_WIDGET_SECRET', ''),

    // Add the chat bubble to every page of the "web" middleware group.
    'sitewide' => env('KOLOSSUS_CHAT_SITEWIDE', true),

    // Pages that never get the bubble, as Request::is() patterns ("account/*").
    // The defaults are Laravel's developer and admin tools.
    'exclude_paths' => [
        'telescope', 'telescope/*',
        'horizon', 'horizon/*',
        'pulse', 'pulse/*',
        'nova', 'nova/*', 'nova-api/*',
        'log-viewer', 'log-viewer/*',
        '_debugbar/*',
    ],

    // "", "light" or "dark".
    'theme' => env('KOLOSSUS_CHAT_THEME', ''),

    // "" or a hex colour like "#aaf684".
    'primary_color' => env('KOLOSSUS_CHAT_PRIMARY_COLOR', ''),

    // "", "bubble", "sidebar" or "bottom_bar".
    'layout' => env('KOLOSSUS_CHAT_LAYOUT', ''),

    // "remember" (as the visitor left it), "open" or "closed".
    'open_on_load' => env('KOLOSSUS_CHAT_OPEN_ON_LOAD', 'remember'),

    // Bubble corner: "", "right" or "left".
    'position' => env('KOLOSSUS_CHAT_POSITION', ''),

    // Bubble distance from the side and bottom edges: "" or 0-1000 (pixels).
    'offset_x' => env('KOLOSSUS_CHAT_OFFSET_X', ''),
    'offset_y' => env('KOLOSSUS_CHAT_OFFSET_Y', ''),

    // "", "small", "medium" or "large".
    'bubble_size' => env('KOLOSSUS_CHAT_BUBBLE_SIZE', ''),

];
