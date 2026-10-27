# KolossusAI Chat for Laravel

Add your KolossusAI chat assistant to a Laravel site with one command. Works with
Laravel 8, 9, 10, 11, 12 and 13 (PHP 7.4+).

## Install

Run this in your Laravel project folder (the one with `artisan`):

```sh
composer require kolossusai/chat-laravel
```

Laravel turns the package on by itself (package discovery) and it adds the chat bubble to
every page; nothing else in your project changes. With no token set, the chat is the
[free FAQ assistant](#free-faq-assistant-no-kolossus-account-needed).

Then add your widget token (from Kolossus: your website widget's **Plugins → Laravel**) to
your environment variables (usually the `.env` file, or your server's settings):

```dotenv
KOLOSSUS_CHAT_WIDGET_TOKEN=wt_YOUR_TOKEN
```

That's it: every page now shows your own chat. Also add your site's domain to the widget's
**allowed domains** in Kolossus, or the chat will not open there. If `.env` already has a
`KOLOSSUS_CHAT_WIDGET_TOKEN` line, change it rather than adding a second one (Laravel uses
the first).

On your live server, set your token the same way (in its `.env`, or where your host keeps
environment variables: Forge, Vapor, Docker, ...). Without a token there, the live site
shows the free FAQ assistant. If you cache your config (`php artisan config:cache`), run it
again after changing them.

Optional: `php artisan kolossus:install` writes the token line into `.env` for you
(`php artisan kolossus:install wt_YOUR_TOKEN`, or `free-laravel` without a token).

## Free FAQ assistant (no Kolossus account needed)

No Kolossus account yet? Use the free FAQ assistant. It answers your visitors'
questions about your website: products, prices, policies, opening hours,
contact details and more.

```sh
composer require kolossusai/chat-laravel
```

That's it: every page now shows the chat bubble, also on your live server: with no
`KOLOSSUS_CHAT_WIDGET_TOKEN` set, the package uses the free FAQ assistant.

**What you get**

* Answers based on your website's public pages.
* **10 free messages per visitor.**
* Visitors chat anonymously.

**Good to know**

* It works on your live website. On `localhost` it can't answer yet.
* It can't answer about orders, accounts or anything behind a login.
* You can change the colour, layout, bubble corner, size and more: see [Look](#look) below.

**Need more?** For more messages or your own trained agent, contact the
KolossusAI team at [kolossusai.in](https://kolossusai.in), then add your widget token to
your environment variables (usually the `.env` file): `KOLOSSUS_CHAT_WIDGET_TOKEN=wt_YOUR_TOKEN`

## Put the chat where you want it

The bubble is added to every page of the `web` middleware group, just before
`</body>`. To place the chat yourself instead, use these Blade directives in your
templates:

```blade
@kolossusChat                                     {{-- the chat bubble --}}
@kolossusChatButton('Chat with us', 'btn btn-primary')   {{-- a button that opens the chat --}}
@kolossusChatScript                               {{-- only the widget script --}}
```

A page that uses one of them never gets a second, automatic bubble. To turn the
automatic bubble off everywhere, set `KOLOSSUS_CHAT_SITEWIDE=false`.

Pages are left untouched when they are not full HTML pages (JSON, files, downloads,
errors), when they are Livewire, Inertia, Turbo or htmx partial updates, and on
Telescope, Horizon, Pulse, Nova, Log Viewer and Debugbar. To skip more pages, publish
the config and add them to `exclude_paths`:

```sh
php artisan vendor:publish --tag=kolossus-chat-config
```

```php
'exclude_paths' => ['admin', 'admin/*', 'checkout/*'],
```

## Logged-in users

Set your widget secret (from the same widget in Kolossus) to let the chat know who a
logged-in user is:

```dotenv
KOLOSSUS_CHAT_WIDGET_SECRET=ws_YOUR_SECRET
```

The secret stays on your server. Only a signature (HMAC-SHA256) of the user's email is
sent to the browser, with their email and name (`email` and `name` of your user model).

## Look

These override the widget's own settings from Kolossus; leave them out to keep those.

| `.env` key | Values |
|---|---|
| `KOLOSSUS_CHAT_THEME` | `light`, `dark` |
| `KOLOSSUS_CHAT_PRIMARY_COLOR` | a hex colour, like `#aaf684` |
| `KOLOSSUS_CHAT_LAYOUT` | `bubble`, `sidebar`, `bottom_bar` |
| `KOLOSSUS_CHAT_OPEN_ON_LOAD` | `remember` (default), `open`, `closed` |
| `KOLOSSUS_CHAT_POSITION` | `right`, `left` (bubble corner) |
| `KOLOSSUS_CHAT_OFFSET_X` / `KOLOSSUS_CHAT_OFFSET_Y` | 0–1000, pixels from the side / bottom edge |
| `KOLOSSUS_CHAT_BUBBLE_SIZE` | `small`, `medium`, `large` |

Invalid values are ignored and logged as a warning in your Laravel log.

If your site sends a Content-Security-Policy with a nonce (`Vite::useCspNonce()`), the
widget script gets that nonce. Your policy must also allow your Kolossus Host URL in
`script-src` and `connect-src` (with its `wss://` address, for the live chat connection).

To change the HTML of the bubble, button or script tag, publish the views:

```sh
php artisan vendor:publish --tag=kolossus-chat-views
```

## Remove

```sh
php artisan kolossus:install --undo
composer remove kolossusai/chat-laravel
```

`--undo` removes the two lines `kolossus:install` added; other `KOLOSSUS_CHAT_` lines
you added yourself stay. While the package is installed the chat stays (as the free FAQ
assistant, with no token set): `composer remove` takes it off, or set
`KOLOSSUS_CHAT_SITEWIDE=false` to keep only the Blade directives.

## Self-hosted Kolossus

Pass your server with `--host` (the dashboard command already includes it):

```sh
php artisan kolossus:install wt_YOUR_TOKEN --host=https://kolossus.example.com
```

The Host URL must use `https://`; plain `http://` works only for `localhost` while testing.

## Development

```sh
composer install
composer test
```

## License

BSD 3-Clause. See [LICENSE](LICENSE).
