<?php

namespace KolossusAI\Chat\Tests;

use KolossusAI\Chat\ChatConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChatConfigTest extends TestCase
{
    private function config(array $raw): ChatConfig
    {
        return ChatConfig::fromArray($raw + ['host_url' => 'https://engine.kolossus.ai', 'widget_token' => 'wt_abcdefgh12345678']);
    }

    public function test_valid_minimal_config_is_configured(): void
    {
        $cfg = $this->config([]);
        $this->assertTrue($cfg->configured());
        $this->assertSame([], $cfg->problems);
        $this->assertSame('https://engine.kolossus.ai/static/widget/widget.js', $cfg->scriptUrl());
        $this->assertSame('remember', $cfg->openOnLoad);
        $this->assertTrue($cfg->sitewide);
    }

    public function test_config_file_defaults_to_the_free_faq_assistant(): void
    {
        $file = __DIR__.'/../config/kolossus-chat.php';
        try {
            foreach ([false, ''] as $value) {
                // Not set, or set but empty: the free FAQ assistant
                putenv($value === false ? 'KOLOSSUS_CHAT_WIDGET_TOKEN' : 'KOLOSSUS_CHAT_WIDGET_TOKEN=');
                $this->assertSame('free-laravel', (require $file)['widget_token'], var_export($value, true));
            }
            putenv('KOLOSSUS_CHAT_WIDGET_TOKEN=wt_abcdefgh12345678');
            $this->assertSame('wt_abcdefgh12345678', (require $file)['widget_token']);
        } finally {
            putenv('KOLOSSUS_CHAT_WIDGET_TOKEN');
        }
    }

    public function test_missing_token_is_not_configured_without_problems(): void
    {
        $cfg = $this->config(['widget_token' => '']);
        $this->assertFalse($cfg->configured());
        $this->assertSame([], $cfg->problems);
    }

    public function test_free_faq_assistant_token_and_no_secret(): void
    {
        $cfg = $this->config(['widget_token' => 'free-laravel', 'widget_secret' => 'ws_secret12345678']);
        $this->assertTrue($cfg->configured());
        $this->assertSame([], $cfg->problems);
        $this->assertSame('free-laravel', $cfg->widgetToken);
        // Free chats are always anonymous: the secret is not used
        $this->assertSame('', $cfg->widgetSecret);
    }

    public function test_other_free_tokens_are_rejected(): void
    {
        $cfg = $this->config(['widget_token' => 'free-wordpress']);
        $this->assertFalse($cfg->configured());
        $this->assertCount(1, $cfg->problems);
    }

    public static function hostUrls(): array
    {
        return [
            'https' => ['https://chat.example.com', 'https://chat.example.com'],
            'path and query dropped' => ['https://chat.example.com/x/y?z=1', 'https://chat.example.com'],
            'port kept' => ['https://chat.example.com:8443/', 'https://chat.example.com:8443'],
            'upper case' => ['HTTPS://Chat.Example.COM', 'https://chat.example.com'],
            'http localhost' => ['http://localhost:8000', 'http://localhost:8000'],
            'http 127.0.0.1' => ['http://127.0.0.1:8005', 'http://127.0.0.1:8005'],
            'http ipv6 loopback' => ['http://[::1]:8000', 'http://[::1]:8000'],
            'http network address' => ['http://192.168.1.5:8005', ''],
            'http public host' => ['http://chat.example.com', ''],
            'no scheme' => ['chat.example.com', ''],
            'other scheme' => ['ftp://chat.example.com', ''],
            'javascript' => ['javascript:alert(1)', ''],
            'bad port' => ['https://chat.example.com:99999', ''],
            'dollar in host' => ['https://a$1b.example.com', ''],
            'backslash in host' => ['https://a\\b.example.com', ''],
            'quote in host' => ['https://a"b.example.com', ''],
            'ipv6' => ['https://[2001:db8::1]:8443', 'https://[2001:db8::1]:8443'],
            'punycode' => ['https://xn--bcher-kva.de', 'https://xn--bcher-kva.de'],
        ];
    }

    /**
     * @dataProvider hostUrls
     */
    #[DataProvider('hostUrls')] // PHPUnit 10+; the annotation above is for PHPUnit 9 (Laravel 8/9)
    public function test_host_url_rules(string $value, string $expected): void
    {
        $this->assertSame($expected, ChatConfig::cleanHostUrl($value));
        $cfg = $this->config(['host_url' => $value]);
        $this->assertSame($expected, $cfg->hostUrl);
        $this->assertCount($expected === '' ? 1 : 0, $cfg->problems);
    }

    public function test_secret_in_token_field_is_rejected(): void
    {
        $cfg = $this->config(['widget_token' => 'ws_abcdefgh12345678']);
        $this->assertSame('', $cfg->widgetToken);
        $this->assertFalse($cfg->configured());
        $this->assertStringContainsString('widget secret', $cfg->problems[0]);
    }

    public function test_malformed_token_is_rejected(): void
    {
        foreach (['wt_short', 'abc', 'wt_abc"def12345', 'wt_abcdefgh12345678 '.'x'] as $token) {
            $cfg = $this->config(['widget_token' => $token]);
            $this->assertSame('', $cfg->widgetToken, $token);
            $this->assertCount(1, $cfg->problems, $token);
        }
    }

    public function test_secret_validation(): void
    {
        $this->assertSame('ws_secret12345678', $this->config(['widget_secret' => 'ws_secret12345678'])->widgetSecret);
        $bad = $this->config(['widget_secret' => 'wt_secret12345678']);
        $this->assertSame('', $bad->widgetSecret);
        $this->assertCount(1, $bad->problems);
    }

    public function test_sitewide_flag_values(): void
    {
        foreach ([[true, true], [false, false], [null, true], ['', true], ['false', false], ['0', false], ['off', false], ['no', false], ['true', true], [0, false], [1, true]] as [$value, $expected]) {
            $this->assertSame($expected, $this->config(['sitewide' => $value])->sitewide, var_export($value, true));
        }
    }

    public function test_look_settings(): void
    {
        $cfg = $this->config([
            'theme' => 'Dark', 'primary_color' => '#ABC', 'layout' => 'sidebar', 'open_on_load' => 'OPEN',
            'position' => 'left', 'offset_x' => '24', 'offset_y' => 0, 'bubble_size' => 'large',
        ]);
        $this->assertSame([], $cfg->problems);
        $this->assertSame('dark', $cfg->theme);
        $this->assertSame('#aabbcc', $cfg->primaryColor);
        $this->assertSame('sidebar', $cfg->layout);
        $this->assertSame('open', $cfg->openOnLoad);
        $this->assertSame('left', $cfg->position);
        $this->assertSame('24', $cfg->offsetX);
        $this->assertSame('0', $cfg->offsetY);
        $this->assertSame('large', $cfg->bubbleSize);
    }

    public function test_invalid_look_settings_are_dropped_with_problems(): void
    {
        $cfg = $this->config([
            'theme' => 'blue', 'primary_color' => 'red', 'layout' => 'popup', 'open_on_load' => 'maybe',
            'position' => 'top', 'offset_x' => '-5', 'offset_y' => '1001', 'bubble_size' => 'huge',
        ]);
        $this->assertCount(8, $cfg->problems);
        $this->assertSame('', $cfg->theme);
        $this->assertSame('', $cfg->primaryColor);
        $this->assertSame('', $cfg->layout);
        $this->assertSame('remember', $cfg->openOnLoad);
        $this->assertSame('', $cfg->position);
        $this->assertSame('', $cfg->offsetX);
        $this->assertSame('', $cfg->offsetY);
        $this->assertSame('', $cfg->bubbleSize);
    }

    public function test_offsets_reject_decimals_and_booleans(): void
    {
        $this->assertSame('', $this->config(['offset_x' => '1.5'])->offsetX);
        $this->assertSame('', $this->config(['offset_x' => true])->offsetX);
        $this->assertSame('7', $this->config(['offset_x' => '007'])->offsetX);
    }

    public function test_exclude_paths(): void
    {
        $this->assertSame(['account/*', 'admin'], $this->config(['exclude_paths' => ['/account/*', 'admin', 'admin']])->excludePaths);
        $this->assertSame(['/'], $this->config(['exclude_paths' => '/'])->excludePaths);
        $bad = $this->config(['exclude_paths' => ['ok', 5]]);
        $this->assertSame([], $bad->excludePaths);
        $this->assertCount(1, $bad->problems);
    }
}
