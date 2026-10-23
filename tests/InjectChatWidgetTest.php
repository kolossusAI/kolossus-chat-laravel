<?php

namespace KolossusAI\Chat\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

class InjectChatWidgetTest extends TestCase
{
    private const PAGE = '<html><head><title>Shop</title></head><body><h1>Hello</h1></body></html>';

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/', fn () => response(self::PAGE));
            Route::get('/upper', fn () => response('<HTML><BODY>x</BODY >tail</HTML>'));
            Route::get('/two-bodies', fn () => response('<body><template></body></template>end</body></html>'));
            Route::get('/json', fn () => response()->json(['a' => '</body>']));
            Route::get('/text', fn () => response('</body>', 200, ['Content-Type' => 'text/plain']));
            Route::get('/missing', fn () => response(self::PAGE, 404));
            Route::get('/fragment', fn () => response('<p>no body end</p>'));
            Route::get('/download', fn () => response(self::PAGE, 200, ['Content-Disposition' => 'attachment; filename="a.html"']));
            Route::get('/gzip', fn () => response(self::PAGE, 200, ['Content-Encoding' => 'gzip']));
            Route::get('/stream', fn () => response()->stream(function () {
                echo self::PAGE;
            }, 200, ['Content-Type' => 'text/html']));
            Route::get('/has-marker', fn () => response('<body><div data-kolossus="wt_other1234567"></div></body>'));
            Route::get('/has-script', fn () => response('<body>'.$this->scriptTag().'</body>'));
            Route::get('/directive', fn () => response(Blade::render('<body>@kolossusChatButton(\'Ask\')</body>')));
            Route::get('/telescope/requests', fn () => response(self::PAGE));
            Route::get('/account/settings', fn () => response(self::PAGE));
            Route::get('/length', fn () => response(self::PAGE, 200, ['Content-Length' => (string) strlen(self::PAGE)]));
            Route::get('/view', fn () => view('page', ['name' => 'Ann']));
        });
        Route::get('/no-web', fn () => response(self::PAGE));
    }

    public function test_adds_script_and_marker_before_body_end(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertSame(
            '<html><head><title>Shop</title></head><body><h1>Hello</h1>'
            .$this->scriptTag()
            .'<div data-kolossus="'.self::TOKEN.'" data-kolossus-open="remember" hidden aria-hidden="true"></div>'
            .'</body></html>',
            $html
        );
    }

    public function test_uses_last_body_end_and_ignores_case(): void
    {
        $this->assertStringEndsWith('</div></BODY >tail</HTML>', $this->get('/upper')->getContent());
        $html = $this->get('/two-bodies')->getContent();
        $this->assertStringStartsWith('<body><template></body></template>end'.$this->scriptTag(), $html);
        $this->assertSame(1, substr_count($html, 'widget.js'));
    }

    public function test_leaves_non_html_pages_alone(): void
    {
        foreach (['/json', '/text', '/fragment', '/download', '/gzip'] as $url) {
            $this->assertStringNotContainsString('widget.js', $this->get($url)->getContent(), $url);
        }
        $this->assertStringNotContainsString('widget.js', $this->get('/missing')->assertNotFound()->getContent());
        $this->assertStringNotContainsString('widget.js', $this->get('/stream')->streamedContent());
    }

    public function test_only_web_group_pages_get_the_bubble(): void
    {
        $this->assertStringNotContainsString('widget.js', $this->get('/no-web')->getContent());
    }

    public function test_page_that_placed_the_chat_itself_is_left_alone(): void
    {
        $this->assertSame('<body><div data-kolossus="wt_other1234567"></div></body>', $this->get('/has-marker')->getContent());
        $this->assertSame('<body>'.$this->scriptTag().'</body>', $this->get('/has-script')->getContent());

        $html = $this->get('/directive')->getContent();
        $this->assertSame(1, substr_count($html, 'widget.js'));
        $this->assertStringContainsString('<button type="button" class="kolossus-chat-button"', $html);
        $this->assertStringNotContainsString('hidden aria-hidden', $html);
    }

    public function test_partial_page_requests_are_left_alone(): void
    {
        foreach (['X-Livewire', 'X-Inertia', 'Turbo-Frame', 'HX-Request'] as $header) {
            $this->assertSame(self::PAGE, $this->get('/', [$header => 'true'])->getContent(), $header);
        }
    }

    public function test_excluded_paths(): void
    {
        $this->assertSame(self::PAGE, $this->get('/telescope/requests')->getContent());
        config(['kolossus-chat.exclude_paths' => ['account/*']]);
        $this->assertSame(self::PAGE, $this->get('/account/settings')->getContent());
        $this->assertStringContainsString('widget.js', $this->get('/')->getContent());
    }

    public function test_not_configured_or_sitewide_off_changes_nothing(): void
    {
        config(['kolossus-chat.widget_token' => '']);
        $this->assertSame(self::PAGE, $this->get('/')->getContent());
        config(['kolossus-chat.widget_token' => self::TOKEN, 'kolossus-chat.sitewide' => 'false']);
        $this->assertSame(self::PAGE, $this->get('/')->getContent());
        config(['kolossus-chat.sitewide' => true, 'kolossus-chat.host_url' => 'http://192.168.1.5']);
        $this->assertSame(self::PAGE, $this->get('/')->getContent());
    }

    public function test_site_view_assertions_keep_working(): void
    {
        View::addLocation(__DIR__.'/views');
        $this->get('/view')
            ->assertOk()
            ->assertViewIs('page')
            ->assertViewHas('name', 'Ann')
            ->assertSee('Hello Ann')
            ->assertSee(self::HOST.'/static/widget/widget.js', false);
    }

    public function test_a_broken_view_sends_the_page_without_the_chat(): void
    {
        View::replaceNamespace('kolossus-chat', [__DIR__.'/views/broken']);
        Log::spy();
        $this->assertSame(self::PAGE, $this->get('/')->assertOk()->getContent());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_content_length_is_updated(): void
    {
        $response = $this->get('/length');
        $this->assertSame((string) strlen($response->getContent()), $response->headers->get('Content-Length'));
    }

    public function test_look_settings_become_attributes(): void
    {
        config([
            'kolossus-chat.theme' => 'dark', 'kolossus-chat.primary_color' => '#AAF684',
            'kolossus-chat.layout' => 'bottom_bar', 'kolossus-chat.open_on_load' => 'closed',
            'kolossus-chat.position' => 'left', 'kolossus-chat.offset_x' => '10',
            'kolossus-chat.offset_y' => '20', 'kolossus-chat.bubble_size' => 'small',
        ]);
        $this->assertStringContainsString(
            '<div data-kolossus="'.self::TOKEN.'" data-kolossus-theme="dark" data-kolossus-color="#aaf684" '
            .'data-kolossus-layout="bottom_bar" data-kolossus-open="closed" data-kolossus-position="left" '
            .'data-kolossus-offset-x="10" data-kolossus-offset-y="20" data-kolossus-size="small" hidden aria-hidden="true"></div>',
            $this->get('/')->getContent()
        );
    }

    public function test_logged_in_user_is_signed_only_with_a_secret(): void
    {
        $user = new GenericUser(['id' => 1, 'email' => ' Ann@Example.COM ', 'name' => 'Ann "A" <b>', 'remember_token' => null]);

        $this->assertStringNotContainsString('data-user', $this->actingAs($user)->get('/')->getContent());

        config(['kolossus-chat.widget_secret' => self::SECRET]);
        $html = $this->actingAs($user)->get('/')->getContent();
        $this->assertStringContainsString('data-user-email="ann@example.com"', $html);
        $this->assertStringContainsString('data-user-name="Ann &quot;A&quot; &lt;b&gt;"', $html);
        $this->assertStringContainsString('data-user-hash="'.hash_hmac('sha256', 'ann@example.com', self::SECRET).'"', $html);
        $this->assertStringNotContainsString(self::SECRET, $html);
    }

    public function test_free_faq_assistant_page_and_no_signing(): void
    {
        config(['kolossus-chat.widget_token' => 'free-laravel', 'kolossus-chat.widget_secret' => self::SECRET]);
        $user = new GenericUser(['id' => 1, 'email' => 'ann@example.com', 'name' => 'Ann', 'remember_token' => null]);
        $html = $this->actingAs($user)->get('/')->getContent();
        $this->assertStringContainsString('data-kolossus="free-laravel"', $html);
        $this->assertStringContainsString($this->scriptTag(), $html);
        // Free chats are always anonymous
        $this->assertStringNotContainsString('data-user', $html);
    }

    public function test_user_fields_that_are_not_text_do_not_hide_the_chat(): void
    {
        config(['kolossus-chat.widget_secret' => self::SECRET]);
        $user = new GenericUser(['id' => 3, 'email' => 'cy@example.com', 'name' => new \stdClass(), 'remember_token' => null]);
        $html = $this->actingAs($user)->get('/')->getContent();
        $this->assertStringContainsString('data-user-email="cy@example.com"', $html);
        $this->assertStringNotContainsString('data-user-name', $html);

        $user = new GenericUser(['id' => 4, 'email' => ['x'], 'name' => 'Dee', 'remember_token' => null]);
        $html = $this->actingAs($user)->get('/')->getContent();
        $this->assertStringContainsString('data-kolossus="'.self::TOKEN.'"', $html);
        $this->assertStringNotContainsString('data-user', $html);
    }

    public function test_guest_and_user_without_valid_email_are_not_signed(): void
    {
        config(['kolossus-chat.widget_secret' => self::SECRET]);
        $this->assertStringNotContainsString('data-user', $this->get('/')->getContent());
        $user = new GenericUser(['id' => 2, 'email' => 'not-an-email', 'name' => 'Bob', 'remember_token' => null]);
        $this->assertStringNotContainsString('data-user', $this->actingAs($user)->get('/')->getContent());
    }
}
