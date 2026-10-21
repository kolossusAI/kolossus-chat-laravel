<?php

namespace KolossusAI\Chat\Console;

use Illuminate\Console\Command;
use KolossusAI\Chat\ChatConfig;

/**
 * php artisan kolossus:install wt_YOUR_TOKEN [--host=https://kolossus.example.com]
 * php artisan kolossus:install --undo
 *
 * Writes the Host URL and public widget token into .env. A line that already sets one
 * of them is changed in place; otherwise both are added at the end under a comment.
 * No other line is touched. --undo removes those lines again.
 */
class InstallCommand extends Command
{
    protected $signature = 'kolossus:install
        {token? : Your public widget token (wt_...), from Kolossus}
        {--host= : Your Kolossus server (default: '.ChatConfig::CLOUD_HOST_URL.')}
        {--undo : Remove the KolossusAI Chat lines from .env}';

    protected $description = 'Turn KolossusAI Chat on: write your widget token into .env';

    private const HOST_KEY = 'KOLOSSUS_CHAT_HOST_URL';

    private const TOKEN_KEY = 'KOLOSSUS_CHAT_WIDGET_TOKEN';

    private const COMMENT = '# KolossusAI Chat (remove with: php artisan kolossus:install --undo)';

    private const HOST_RULE = 'The Kolossus Host URL must start with https://, for example '.ChatConfig::CLOUD_HOST_URL.'. '
        .'Plain http:// works only for a server on this machine (localhost or 127.0.0.1).';

    public function handle(): int
    {
        $token = trim((string) $this->argument('token'));
        $host = trim((string) $this->option('host'));
        $undo = (bool) $this->option('undo');

        if ($undo && ($token !== '' || $host !== '')) {
            return $this->failWith('--undo takes no token or --host.');
        }

        $path = $this->laravel->environmentFilePath();
        if (! is_file($path)) {
            return $this->failWith("No .env file at {$path}. Run this in your Laravel project folder, or add these lines to your .env by hand:", $token, $host);
        }
        $text = is_readable($path) ? @file_get_contents($path) : false;
        if ($text === false) {
            return $this->failWith("Could not read {$path}.", $token, $host);
        }

        if ($undo) {
            try {
                return $this->undo($path, $text);
            } catch (\RuntimeException $e) {
                return $this->failWith($e->getMessage().' Remove the '.self::HOST_KEY.' and '.self::TOKEN_KEY.' lines by hand.');
            }
        }

        if ($token === '') {
            if ($host !== '') {
                return $this->failWith("--host needs your widget token too: php artisan kolossus:install wt_YOUR_TOKEN --host={$host}");
            }
            if ($this->currentValue($text, self::TOKEN_KEY) !== '') {
                $this->info("KolossusAI Chat is already set up in {$path}. Nothing changed.");

                return self::SUCCESS;
            }

            return $this->failWith('Pass your widget token: php artisan kolossus:install wt_YOUR_TOKEN (copy the command from your widget in Kolossus → Plugins → Laravel).');
        }
        if (str_starts_with($token, 'ws_')) {
            return $this->failWith('That is the widget secret (ws_...). Keep it private; pass the public widget token, which starts with wt_.');
        }
        if (! preg_match(ChatConfig::TOKEN_PATTERN, $token)) {
            return $this->failWith('The widget token should start with wt_ followed by letters and numbers.');
        }
        $hostUrl = ChatConfig::cleanHostUrl($host !== '' ? $host : ChatConfig::CLOUD_HOST_URL);
        if ($hostUrl === '') {
            return $this->failWith("--host={$host} is not accepted. ".self::HOST_RULE);
        }

        try {
            return $this->install($path, $text, $hostUrl, $token);
        } catch (\RuntimeException $e) {
            return $this->failWith($e->getMessage().' Add these lines to your .env by hand:', $token, $hostUrl);
        }
    }

    private function install(string $path, string $text, string $hostUrl, string $token): int
    {
        $values = [self::HOST_KEY => $hostUrl, self::TOKEN_KEY => $token];
        $nl = str_contains($text, "\r\n") ? "\r\n" : "\n";
        $new = $text;
        $missing = [];
        foreach ($values as $key => $value) {
            $count = 0;
            $new = preg_replace_callback($this->linePattern($key), fn () => $key.'='.$value, $new, -1, $count);
            if ($count === 0) {
                $missing[] = $key.'='.$value;
            }
        }
        if ($missing !== []) {
            if ($new !== '' && ! str_ends_with($new, "\n")) {
                $new .= $nl;
            }
            $new .= ($new === '' ? '' : $nl).implode($nl, [self::COMMENT, ...$missing]).$nl;
        }

        if ($new === $text) {
            $this->info("KolossusAI Chat is already set up in {$path} with this token. Nothing changed.");
        } else {
            $this->write($path, $new);
            $this->info("Done. KolossusAI Chat is set up in {$path}");
        }
        $this->line('Widget token '.substr($token, 0, 12)."… on {$hostUrl}");
        $this->afterChange();
        $this->line('Every page now shows the chat bubble. Add your site\'s domain to the widget\'s allowed domains in Kolossus.');

        return self::SUCCESS;
    }

    private function undo(string $path, string $text): int
    {
        $new = preg_replace('/^'.preg_quote(self::COMMENT, '/').'[^\S\r\n]*(?:\r?\n|\z)/m', '', $text);
        foreach ([self::HOST_KEY, self::TOKEN_KEY] as $key) {
            $new = preg_replace(substr($this->linePattern($key), 0, -2).'(?:\r?\n|\z)/m', '', $new);
        }
        if ($new === $text) {
            $this->info("Nothing to remove: {$path} has no KolossusAI Chat lines.");

            return self::SUCCESS;
        }
        $this->write($path, $new);
        $this->info("Removed the KolossusAI Chat lines from {$path}");
        $this->afterChange();
        $this->line('To remove the package too: composer remove kolossusai/chat-laravel');

        return self::SUCCESS;
    }

    /** A whole .env line that sets $key (also "export KEY=..."), as a multiline pattern. */
    private function linePattern(string $key): string
    {
        return '/^[^\S\r\n]*(?:export[^\S\r\n]+)?'.$key.'[^\S\r\n]*=[^\r\n]*/m';
    }

    /** The value .env gives $key, or ''. */
    private function currentValue(string $text, string $key): string
    {
        if (! preg_match($this->linePattern($key), $text, $match)) {
            return '';
        }

        return trim(substr($match[0], strpos($match[0], '=') + 1), " \t\"'");
    }

    private function write(string $path, string $text): void
    {
        if (! is_writable($path) || @file_put_contents($path, $text, LOCK_EX) === false) {
            throw new \RuntimeException("Could not write {$path}.");
        }
    }

    private function afterChange(): void
    {
        if ($this->laravel->configurationIsCached()) {
            $this->warn('Your config is cached: run php artisan config:cache so the site uses the new values.');
        }
    }

    /** Print the error (plus the lines to add by hand, when known) and return failure. */
    private function failWith(string $message, string $token = '', string $host = ''): int
    {
        $this->error($message);
        if (preg_match(ChatConfig::TOKEN_PATTERN, $token)) {
            $hostUrl = ChatConfig::cleanHostUrl($host !== '' ? $host : ChatConfig::CLOUD_HOST_URL) ?: ChatConfig::CLOUD_HOST_URL;
            $this->line('');
            $this->line('    '.self::HOST_KEY.'='.$hostUrl);
            $this->line('    '.self::TOKEN_KEY.'='.$token);
        }

        return self::FAILURE;
    }
}
