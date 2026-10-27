<?php

namespace KolossusAI\Chat;

use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

/**
 * Builds the HTML that embeds the Kolossus chat widget.
 *
 * The widget script (served by the Kolossus server) opens the chat for the first
 * element with a data-kolossus attribute and binds every such element as a button.
 * The site-wide bubble is therefore a hidden marker element.
 */
class KolossusChat
{
    /**
     * Request attribute set once the script tag is on the page: the script goes out at
     * most once, and InjectChatWidget leaves a page that used a Blade directive alone.
     */
    public const SCRIPT_DONE = 'kolossus_chat.script_done';

    /** @var array<string, true> Problems already logged by this process. */
    private static array $logged = [];

    /** The validated config; each distinct problem is logged once per process. */
    public function config(): ChatConfig
    {
        // Read from the container each time (not injected): under Octane the config
        // repository is replaced for every request.
        $cfg = ChatConfig::fromArray((array) config('kolossus-chat', []));
        foreach ($cfg->problems as $problem) {
            if (! isset(self::$logged[$problem])) {
                self::$logged[$problem] = true;
                Log::warning("KolossusAI Chat: {$problem} The value is ignored.");
            }
        }

        return $cfg;
    }

    /** @kolossusChat: the site-wide chat bubble (the widget script plus a hidden marker). */
    public function widget(?Request $request = null): HtmlString
    {
        return $this->safely(function (ChatConfig $cfg) use ($request) {
            $request ??= $this->currentRequest();

            return $this->scriptOnce($request, $cfg).$this->marker($request, $cfg);
        });
    }

    /**
     * @kolossusChatButton('Chat with us', 'btn'): a button that opens the chat.
     *
     * Any label (string, number, HtmlString, ...) is shown as escaped text.
     *
     * @param  mixed  $label
     * @param  mixed  $class
     */
    public function button($label = null, $class = ''): HtmlString
    {
        return $this->safely(function (ChatConfig $cfg) use ($label, $class) {
            $request = $this->currentRequest();
            $extra = array_filter(array_map(
                fn ($c) => preg_replace('/[^A-Za-z0-9_-]/', '', $c),
                preg_split('/\s+/', is_scalar($class) ? (string) $class : '') ?: []
            ));
            $label = $label === null ? __('Chat with us') : $label;
            $html = view('kolossus-chat::button', [
                'classes' => implode(' ', ['kolossus-chat-button', ...$extra]),
                'attrs' => $this->triggerAttributes($request, $cfg),
                'label' => self::isText($label) ? (string) $label : '',
            ])->render();

            return $this->scriptOnce($request, $cfg).trim($html);
        });
    }

    /** @kolossusChatScript: only the widget script; add the triggers yourself. */
    public function script(): HtmlString
    {
        return $this->safely(fn (ChatConfig $cfg) => $this->scriptOnce($this->currentRequest(), $cfg));
    }

    /**
     * $build's HTML when the chat is configured, else nothing. An error is logged and
     * gives nothing too: the chat must never break the page it is on.
     *
     * @param  callable(ChatConfig): string  $build
     */
    private function safely(callable $build): HtmlString
    {
        try {
            $cfg = $this->config();

            return new HtmlString($cfg->configured() ? $build($cfg) : '');
        } catch (\Throwable $e) {
            Log::error('KolossusAI Chat: could not add the chat to the page.', ['exception' => $e]);

            return new HtmlString('');
        }
    }

    /** The widget script tag, at most once per request. */
    private function scriptOnce(?Request $request, ChatConfig $cfg): string
    {
        if ($request !== null) {
            if ($request->attributes->get(self::SCRIPT_DONE)) {
                return '';
            }
            $request->attributes->set(self::SCRIPT_DONE, true);
        }

        return trim(view('kolossus-chat::script', ['src' => $cfg->scriptUrl(), 'nonce' => $this->cspNonce()])->render());
    }

    /**
     * The site's Content-Security-Policy nonce (Vite::useCspNonce()), or null. Without
     * it, a nonce-based policy would block the widget script.
     */
    private function cspNonce(): ?string
    {
        $nonce = app()->bound(Vite::class) ? app(Vite::class)->cspNonce() : null;

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    private function marker(?Request $request, ChatConfig $cfg): string
    {
        return trim(view('kolossus-chat::marker', ['attrs' => $this->triggerAttributes($request, $cfg)])->render());
    }

    /**
     * Escaped data-* attributes the widget script reads from a trigger element: the
     * token, optional look overrides, and the signed identity of a logged-in user.
     */
    private function triggerAttributes(?Request $request, ChatConfig $cfg): HtmlString
    {
        $attrs = [
            'data-kolossus' => $cfg->widgetToken,
            'data-kolossus-theme' => $cfg->theme,
            'data-kolossus-color' => $cfg->primaryColor,
            'data-kolossus-layout' => $cfg->layout,
            'data-kolossus-open' => $cfg->openOnLoad,
            'data-kolossus-position' => $cfg->position,
            'data-kolossus-offset-x' => $cfg->offsetX,
            'data-kolossus-offset-y' => $cfg->offsetY,
            'data-kolossus-size' => $cfg->bubbleSize,
        ] + $this->userIdentity($request, $cfg->widgetSecret);

        $parts = [];
        foreach ($attrs as $name => $value) {
            if ($value !== '') {
                $parts[] = $name.'="'.e($value).'"';
            }
        }

        return new HtmlString(implode(' ', $parts));
    }

    /**
     * data-user-* attributes for a logged-in user, signed with the widget secret.
     *
     * The secret stays on the server: only the HMAC of the email reaches the browser.
     *
     * @return array<string, string>
     */
    private function userIdentity(?Request $request, string $secret): array
    {
        if ($secret === '' || $request === null) {
            return [];
        }
        try {
            $user = $request->user();
        } catch (\Throwable $e) {
            // No auth guard configured (an app without users): nobody to sign.
            return [];
        }
        if ($user === null) {
            return [];
        }
        $email = strtolower($this->text($user->email ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [];
        }
        $name = $this->text($user->name ?? '');

        return [
            'data-user-email' => $email,
            'data-user-name' => $name,
            'data-user-hash' => hash_hmac('sha256', $email, $secret),
        ];
    }

    /**
     * A user field as trimmed text; '' for values that are not text (arrays, objects).
     *
     * @param  mixed  $value
     */
    private function text($value): string
    {
        return self::isText($value) ? trim((string) $value) : '';
    }

    /**
     * A scalar, or an object with __toString (what PHP 8 calls \Stringable; PHP 7.4
     * has no such interface, and instanceof it would be false there).
     *
     * @param  mixed  $value
     */
    private static function isText($value): bool
    {
        return is_scalar($value) || (is_object($value) && method_exists($value, '__toString'));
    }

    private function currentRequest(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }
}
