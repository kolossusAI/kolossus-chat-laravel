<?php

namespace KolossusAI\Chat\Tests;

class InstallCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/kolossus-chat-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->app->useEnvironmentPath($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $name) {
            if (is_file($this->dir.'/'.$name)) {
                unlink($this->dir.'/'.$name);
            }
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function env(?string $text = null): string
    {
        $path = $this->dir.'/.env';
        if ($text !== null) {
            file_put_contents($path, $text);
        }

        return file_get_contents($path);
    }

    public function test_appends_both_lines_with_the_cloud_host(): void
    {
        $this->env("APP_NAME=Shop\nAPP_ENV=local");
        $this->artisan('kolossus:install', ['token' => self::TOKEN])->assertSuccessful();
        $this->assertSame(
            "APP_NAME=Shop\nAPP_ENV=local\n\n# KolossusAI Chat (remove with: php artisan kolossus:install --undo)\n"
            ."KOLOSSUS_CHAT_HOST_URL=https://engine.kolossus.ai\nKOLOSSUS_CHAT_WIDGET_TOKEN=".self::TOKEN."\n",
            $this->env()
        );
    }

    public function test_running_again_changes_nothing_and_new_values_replace_in_place(): void
    {
        $this->env("APP_NAME=Shop\n");
        $this->artisan('kolossus:install', ['token' => self::TOKEN])->assertSuccessful();
        $first = $this->env();
        $this->artisan('kolossus:install', ['token' => self::TOKEN])
            ->expectsOutputToContain('Nothing changed')
            ->assertSuccessful();
        $this->assertSame($first, $this->env());

        $this->artisan('kolossus:install', ['token' => 'wt_newtoken987654321', '--host' => 'http://localhost:8000/'])->assertSuccessful();
        $this->assertSame(
            str_replace(['https://engine.kolossus.ai', self::TOKEN], ['http://localhost:8000', 'wt_newtoken987654321'], $first),
            $this->env()
        );
    }

    public function test_existing_lines_are_changed_in_place_and_others_untouched(): void
    {
        $this->env("KOLOSSUS_CHAT_WIDGET_TOKEN=\"wt_oldtoken1234567\"\r\n# KOLOSSUS_CHAT_HOST_URL=commented\r\nexport KOLOSSUS_CHAT_HOST_URL = https://old.example.com\r\nKOLOSSUS_CHAT_THEME=dark\r\n");
        $this->artisan('kolossus:install', ['token' => self::TOKEN, '--host' => 'https://chat.example.com'])->assertSuccessful();
        $this->assertSame(
            "KOLOSSUS_CHAT_WIDGET_TOKEN=".self::TOKEN."\r\n# KOLOSSUS_CHAT_HOST_URL=commented\r\nKOLOSSUS_CHAT_HOST_URL=https://chat.example.com\r\nKOLOSSUS_CHAT_THEME=dark\r\n",
            $this->env()
        );
    }

    public function test_crlf_file_gets_crlf_lines(): void
    {
        $this->env("APP_NAME=Shop\r\n");
        $this->artisan('kolossus:install', ['token' => self::TOKEN])->assertSuccessful();
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $this->env()));
    }

    public function test_rejects_bad_input_without_touching_env(): void
    {
        $this->env("APP_NAME=Shop\n");
        $this->artisan('kolossus:install', ['token' => 'ws_secret12345678'])->expectsOutputToContain('widget secret')->assertFailed();
        $this->artisan('kolossus:install', ['token' => 'wt_bad'])->assertFailed();
        $this->artisan('kolossus:install', ['token' => self::TOKEN, '--host' => 'http://192.168.1.5:8005'])->expectsOutputToContain('not accepted')->assertFailed();
        $this->artisan('kolossus:install', ['--host' => 'https://chat.example.com'])->expectsOutputToContain('needs your widget token')->assertFailed();
        $this->artisan('kolossus:install', ['token' => self::TOKEN, '--undo' => true])->assertFailed();
        $this->assertSame("APP_NAME=Shop\n", $this->env());
    }

    public function test_no_token_when_already_set_up_is_fine(): void
    {
        $this->env("KOLOSSUS_CHAT_WIDGET_TOKEN=".self::TOKEN."\n");
        $this->artisan('kolossus:install')->expectsOutputToContain('already set up')->assertSuccessful();
        $this->assertSame("KOLOSSUS_CHAT_WIDGET_TOKEN=".self::TOKEN."\n", $this->env());
    }

    public function test_no_token_turns_on_the_free_faq_assistant(): void
    {
        $this->env("APP_NAME=Shop\n");
        $this->artisan('kolossus:install')
            ->expectsOutputToContain('Free FAQ assistant turned on')
            ->assertSuccessful();
        $this->assertSame(
            "APP_NAME=Shop\n\n# KolossusAI Chat (remove with: php artisan kolossus:install --undo)\n"
            ."KOLOSSUS_CHAT_HOST_URL=https://engine.kolossus.ai\nKOLOSSUS_CHAT_WIDGET_TOKEN=free-laravel\n",
            $this->env()
        );
        // Again: already set up, nothing changes
        $this->artisan('kolossus:install')->expectsOutputToContain('already set up')->assertSuccessful();
        // Own token later replaces the free one in place
        $this->artisan('kolossus:install', ['token' => self::TOKEN])->assertSuccessful();
        $this->assertStringContainsString("KOLOSSUS_CHAT_WIDGET_TOKEN=".self::TOKEN."\n", $this->env());
        $this->assertStringNotContainsString('free-laravel', $this->env());
    }

    public function test_no_free_faq_assistant_on_an_own_kolossus_server(): void
    {
        $this->env("KOLOSSUS_CHAT_HOST_URL=https://chat.example.com\n");
        $this->artisan('kolossus:install')->expectsOutputToContain('Pass your widget token')->assertFailed();
        $this->assertSame("KOLOSSUS_CHAT_HOST_URL=https://chat.example.com\n", $this->env());
        // The cloud host already in .env is fine
        $this->env("KOLOSSUS_CHAT_HOST_URL=https://engine.kolossus.ai/\n");
        $this->artisan('kolossus:install')->assertSuccessful();
        $this->assertStringContainsString('KOLOSSUS_CHAT_WIDGET_TOKEN=free-laravel', $this->env());
    }

    public function test_cloud_host_with_a_comment_still_gets_the_free_assistant(): void
    {
        $this->env("KOLOSSUS_CHAT_HOST_URL=https://engine.kolossus.ai # prod\n");
        $this->artisan('kolossus:install')->expectsOutputToContain('Free FAQ assistant turned on')->assertSuccessful();
        $this->assertStringContainsString('KOLOSSUS_CHAT_WIDGET_TOKEN=free-laravel', $this->env());
        // An own server with a comment is still left alone
        $this->env("KOLOSSUS_CHAT_HOST_URL=https://chat.example.com # ours\n");
        $this->artisan('kolossus:install')->expectsOutputToContain('Pass your widget token')->assertFailed();
    }

    public function test_missing_env_file_prints_the_free_lines(): void
    {
        $this->artisan('kolossus:install')
            ->expectsOutputToContain('KOLOSSUS_CHAT_WIDGET_TOKEN=free-laravel')
            ->assertFailed();
        $this->artisan('kolossus:install', ['--undo' => true])
            ->doesntExpectOutputToContain('free-laravel')
            ->assertFailed();
        $this->assertFileDoesNotExist($this->dir.'/.env');
    }

    public function test_free_faq_assistant_when_env_cannot_be_written(): void
    {
        $this->env("APP_NAME=Shop\n");
        chmod($this->dir.'/.env', 0444);
        $this->artisan('kolossus:install')
            ->expectsOutputToContain('KOLOSSUS_CHAT_WIDGET_TOKEN=free-laravel')
            ->assertFailed();
        chmod($this->dir.'/.env', 0644);
        $this->assertSame("APP_NAME=Shop\n", $this->env());
    }

    public function test_missing_env_file_prints_the_lines_to_add(): void
    {
        $this->artisan('kolossus:install', ['token' => self::TOKEN])
            ->expectsOutputToContain('No .env file')
            ->expectsOutputToContain('KOLOSSUS_CHAT_WIDGET_TOKEN='.self::TOKEN)
            ->assertFailed();
        $this->assertFileDoesNotExist($this->dir.'/.env');
    }

    public function test_undo_restores_the_original_file(): void
    {
        foreach (["APP_NAME=Shop\nAPP_ENV=local\n", "APP_NAME=Shop", "APP_NAME=Shop\r\n", ''] as $original) {
            $this->env($original);
            $this->artisan('kolossus:install', ['token' => self::TOKEN])->assertSuccessful();
            $this->artisan('kolossus:install', ['--undo' => true])->assertSuccessful();
            $after = $this->env();
            $this->assertStringNotContainsString('KOLOSSUS', $after, var_export($original, true));
            $this->assertSame(rtrim($original, "\r\n"), rtrim($after, "\r\n"), var_export($original, true));
        }
        $this->artisan('kolossus:install', ['--undo' => true])->expectsOutputToContain('Nothing to remove')->assertSuccessful();
    }

    public function test_read_only_env_fails_cleanly_with_the_lines_to_add(): void
    {
        $this->env("APP_NAME=Shop\n");
        chmod($this->dir.'/.env', 0444);
        $this->artisan('kolossus:install', ['token' => self::TOKEN])
            ->expectsOutputToContain('Could not write')
            ->expectsOutputToContain('KOLOSSUS_CHAT_WIDGET_TOKEN='.self::TOKEN)
            ->assertFailed();
        $this->artisan('kolossus:install', ['--undo' => true])->assertSuccessful();
        chmod($this->dir.'/.env', 0644);
        $this->assertSame("APP_NAME=Shop\n", $this->env());
    }

    public function test_unreadable_env_fails_cleanly(): void
    {
        $this->env("APP_NAME=Shop\n");
        chmod($this->dir.'/.env', 0000);
        $this->artisan('kolossus:install', ['token' => self::TOKEN])->expectsOutputToContain('Could not read')->assertFailed();
        chmod($this->dir.'/.env', 0644);
    }

    public function test_undo_keeps_other_kolossus_settings(): void
    {
        $this->env("KOLOSSUS_CHAT_THEME=dark\nKOLOSSUS_CHAT_WIDGET_TOKEN=".self::TOKEN."\nKOLOSSUS_CHAT_WIDGET_SECRET=".self::SECRET."\n");
        $this->artisan('kolossus:install', ['--undo' => true])->assertSuccessful();
        $this->assertSame("KOLOSSUS_CHAT_THEME=dark\nKOLOSSUS_CHAT_WIDGET_SECRET=".self::SECRET."\n", $this->env());
    }
}
