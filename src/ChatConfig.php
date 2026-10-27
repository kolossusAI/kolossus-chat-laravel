<?php

namespace KolossusAI\Chat;

use Illuminate\Support\Str;

/**
 * The validated kolossus-chat config.
 *
 * Invalid values are dropped (the widget's own setting is used instead) and listed in
 * $problems, which KolossusChat logs once per process and kolossus:install prints.
 */
final class ChatConfig
{
    /** Path of the widget script on the Kolossus server, relative to the Host URL. */
    public const SCRIPT_PATH = '/static/widget/widget.js';

    /** The Kolossus cloud; the same default as the other Kolossus plugins. */
    public const CLOUD_HOST_URL = 'https://engine.kolossus.ai';

    public const TOKEN_PATTERN = '/^wt_[A-Za-z0-9]{8,128}$/';

    /** The free FAQ assistant's token (no Kolossus account): kolossus:install without a token. */
    public const FREE_TOKEN = 'free-laravel';

    public const SECRET_PATTERN = '/^ws_[A-Za-z0-9]{8,128}$/';

    public const MAX_OFFSET = 1000;

    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    private const THEMES = ['', 'light', 'dark'];

    private const LAYOUTS = ['', 'bubble', 'sidebar', 'bottom_bar'];

    private const OPEN_ON_LOAD = ['remember', 'open', 'closed'];

    private const POSITIONS = ['', 'right', 'left'];

    private const BUBBLE_SIZES = ['', 'small', 'medium', 'large'];

    public string $hostUrl = '';

    public string $widgetToken = '';

    public string $widgetSecret = '';

    public bool $sitewide = true;

    /** @var list<string> Request::is() patterns. */
    public array $excludePaths = [];

    public string $theme = '';

    public string $primaryColor = '';

    public string $layout = '';

    public string $openOnLoad = 'remember';

    public string $position = '';

    public string $offsetX = '';

    public string $offsetY = '';

    public string $bubbleSize = '';

    /** @var list<string> */
    public array $problems = [];

    public static function fromArray(array $raw): self
    {
        $cfg = new self();

        $hostUrl = self::text($raw, 'host_url');
        if ($hostUrl !== '') {
            $cfg->hostUrl = self::cleanHostUrl($hostUrl);
            if ($cfg->hostUrl === '') {
                $cfg->problems[] = 'KOLOSSUS_CHAT_HOST_URL must start with https://, for example '.self::CLOUD_HOST_URL.'.';
            }
        }

        $token = self::text($raw, 'widget_token');
        if ($token === self::FREE_TOKEN) {
            $cfg->widgetToken = $token;
        } elseif (Str::startsWith($token, 'ws_')) {
            $cfg->problems[] = 'KOLOSSUS_CHAT_WIDGET_TOKEN holds the widget secret (ws_...). Put it in '
                .'KOLOSSUS_CHAT_WIDGET_SECRET instead; the widget token is the public one that starts with wt_.';
        } elseif ($token !== '' && ! preg_match(self::TOKEN_PATTERN, $token)) {
            $cfg->problems[] = 'KOLOSSUS_CHAT_WIDGET_TOKEN should start with wt_ followed by letters and numbers.';
        } else {
            $cfg->widgetToken = $token;
        }

        $secret = self::text($raw, 'widget_secret');
        if ($cfg->widgetToken === self::FREE_TOKEN) {
            // The free FAQ assistant has no secret: its chats are always anonymous.
        } elseif ($secret !== '' && preg_match(self::SECRET_PATTERN, $secret)) {
            $cfg->widgetSecret = $secret;
        } elseif ($secret !== '') {
            $cfg->problems[] = 'KOLOSSUS_CHAT_WIDGET_SECRET should start with ws_ followed by letters and numbers.';
        }

        $cfg->sitewide = self::flag($raw['sitewide'] ?? true);

        $exclude = $raw['exclude_paths'] ?? [];
        if (is_string($exclude)) {
            $exclude = [$exclude];
        }
        if (is_array($exclude) && array_filter($exclude, fn ($p) => ! is_string($p) || trim($p) === '') === []) {
            // Request::is() matches the path without its leading slash.
            $cfg->excludePaths = array_values(array_unique(array_map(fn ($p) => ltrim(trim($p), '/') ?: '/', $exclude)));
        } else {
            $cfg->problems[] = "exclude_paths must be a list of URL paths, like ['account/*'].";
        }

        $cfg->theme = self::choice($raw, 'theme', self::THEMES, 'KOLOSSUS_CHAT_THEME must be "", "light" or "dark".', $cfg->problems);

        $color = strtolower(self::text($raw, 'primary_color'));
        if ($color !== '' && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $color)) {
            // The widget expects #rrggbb; expand #rgb.
            $cfg->primaryColor = strlen($color) === 7 ? $color : '#'.$color[1].$color[1].$color[2].$color[2].$color[3].$color[3];
        } elseif ($color !== '') {
            $cfg->problems[] = 'KOLOSSUS_CHAT_PRIMARY_COLOR must be a hex colour like "#aaf684".';
        }

        $cfg->layout = self::choice($raw, 'layout', self::LAYOUTS, 'KOLOSSUS_CHAT_LAYOUT must be "", "bubble", "sidebar" or "bottom_bar".', $cfg->problems);

        $openOnLoad = strtolower(self::text($raw, 'open_on_load')) ?: 'remember';
        if (in_array($openOnLoad, self::OPEN_ON_LOAD, true)) {
            $cfg->openOnLoad = $openOnLoad;
        } else {
            $cfg->problems[] = 'KOLOSSUS_CHAT_OPEN_ON_LOAD must be "remember", "open" or "closed".';
        }

        $cfg->position = self::choice($raw, 'position', self::POSITIONS, 'KOLOSSUS_CHAT_POSITION must be "", "right" or "left".', $cfg->problems);

        foreach (['offset_x' => 'offsetX', 'offset_y' => 'offsetY'] as $key => $property) {
            $value = self::text($raw, $key);
            if ($value === '') {
                continue;
            }
            if (! is_bool($raw[$key]) && ctype_digit($value) && (int) $value <= self::MAX_OFFSET) {
                $cfg->{$property} = (string) (int) $value;
            } else {
                $cfg->problems[] = 'KOLOSSUS_CHAT_'.strtoupper($key).' must be a whole number of pixels from 0 to '.self::MAX_OFFSET.'.';
            }
        }

        $cfg->bubbleSize = self::choice($raw, 'bubble_size', self::BUBBLE_SIZES, 'KOLOSSUS_CHAT_BUBBLE_SIZE must be "", "small", "medium" or "large".', $cfg->problems);

        return $cfg;
    }

    /** True when both the Host URL and the widget token are set and valid. */
    public function configured(): bool
    {
        return $this->hostUrl !== '' && $this->widgetToken !== '';
    }

    public function scriptUrl(): string
    {
        return $this->hostUrl.self::SCRIPT_PATH;
    }

    /**
     * scheme://host[:port], or '' when the value is not an acceptable Kolossus URL:
     * https, or http on this machine only (local testing).
     */
    public static function cleanHostUrl(string $value): string
    {
        $parts = parse_url(trim($value));
        if (! is_array($parts)) {
            return '';
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if ($host === '' || ! ($scheme === 'https' || ($scheme === 'http' && in_array($host, self::LOCAL_HOSTS, true)))) {
            return '';
        }
        // A host name, IPv4 or IPv6 address only: the value ends up in .env and in HTML.
        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$|^[0-9a-f:.]+$/', $host)) {
            return '';
        }
        if (Str::contains($host, ':')) {
            $host = "[{$host}]";
        }

        return "{$scheme}://{$host}".(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** A config value as trimmed text: '' for null, false and arrays. */
    private static function text(array $raw, string $key): string
    {
        $value = $raw[$key] ?? '';

        return is_scalar($value) && $value !== false ? trim((string) $value) : '';
    }

    /**
     * sitewide from config or .env: "false" / "0" / "no" / "off" turn it off; empty keeps it on.
     *
     * @param  mixed  $value
     */
    private static function flag($value): bool
    {
        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), ['false', '0', 'no', 'off', '(false)'], true);
        }

        return $value === null ? true : (bool) $value;
    }

    /** @param list<string> $problems */
    private static function choice(array $raw, string $key, array $allowed, string $problem, array &$problems): string
    {
        $value = strtolower(self::text($raw, $key));
        if (in_array($value, $allowed, true)) {
            return $value;
        }
        $problems[] = $problem;

        return '';
    }
}
