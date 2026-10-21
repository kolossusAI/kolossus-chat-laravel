<?php

namespace KolossusAI\Chat\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Log;
use KolossusAI\Chat\KolossusChat;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Adds the chat bubble to every HTML page of the "web" middleware group, so no
 * template has to change.
 *
 * The widget script and the hidden bubble marker go in just before </body>. A page
 * is left exactly as it is when:
 *
 * - the widget token is not set, or kolossus-chat.sitewide is off;
 * - the path matches kolossus-chat.exclude_paths (by default Telescope, Horizon,
 *   Pulse, Nova, Log Viewer and Debugbar);
 * - the response is not a complete HTML page (not 200, not text/html, streamed,
 *   a file, compressed, a download, or without </body>);
 * - it is a partial page for Livewire, Inertia, Turbo or htmx;
 * - the page already used a Blade directive (@kolossusChat, ...), or already holds a
 *   data-kolossus element or the widget script (the site placed the chat itself).
 *
 * Any error while adding the bubble is logged and the original page is returned.
 */
class InjectChatWidget
{
    /** Request headers of partial page updates, which must not get a second bubble. */
    private const PARTIAL_HEADERS = ['X-Livewire', 'X-Inertia', 'Turbo-Frame', 'HX-Request'];

    public function __construct(private readonly KolossusChat $chat)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);
        try {
            if ($response instanceof Response) {
                $this->addWidget($request, $response);
            }
        } catch (\Throwable $e) {
            Log::error('KolossusAI Chat: could not add the chat bubble; the page is sent without it.', ['exception' => $e]);
        }

        return $response;
    }

    private function addWidget(Request $request, Response $response): void
    {
        if (
            $response instanceof StreamedResponse
            || $response instanceof BinaryFileResponse
            || $response->getStatusCode() !== 200
            || ! str_starts_with(strtolower((string) $response->headers->get('Content-Type', '')), 'text/html')
            || $response->headers->has('Content-Encoding')
            || str_contains(strtolower((string) $response->headers->get('Content-Disposition', '')), 'attachment')
            || $request->attributes->get(KolossusChat::SCRIPT_DONE)
        ) {
            return;
        }
        foreach (self::PARTIAL_HEADERS as $header) {
            if ($request->headers->has($header)) {
                return;
            }
        }

        $cfg = $this->chat->config();
        if (! $cfg->configured() || ! $cfg->sitewide) {
            return;
        }
        if ($cfg->excludePaths !== [] && $request->is(...$cfg->excludePaths)) {
            return;
        }

        $content = $response->getContent();
        // Our own script URL only: a site may well have its own /static/widget/widget.js.
        if (! is_string($content) || str_contains($content, 'data-kolossus=') || str_contains($content, $cfg->scriptUrl())) {
            return;
        }
        // The last </body> of the page; tolerant of case and whitespace (</BODY >).
        if (! preg_match_all('#</body\s*>#i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }
        $at = end($matches[0])[1];

        $html = (string) $this->chat->widget($request);
        // setContent() replaces the view a route returned with the plain string; put it
        // back so the site's own tests (assertViewIs, assertViewHas) keep working.
        $original = $response instanceof IlluminateResponse ? $response->getOriginalContent() : null;
        $response->setContent(substr($content, 0, $at).$html.substr($content, $at));
        if (is_object($original)) {
            $response->original = $original;
        }
        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) strlen((string) $response->getContent()));
        }
    }
}
