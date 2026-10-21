<?php

namespace KolossusAI\Chat\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;

class BladeDirectivesTest extends TestCase
{
    public function test_widget_directive(): void
    {
        $this->assertSame(
            $this->scriptTag().'<div data-kolossus="'.self::TOKEN.'" data-kolossus-open="remember" hidden aria-hidden="true"></div>',
            Blade::render('@kolossusChat')
        );
    }

    public function test_button_directive_default_label_and_classes(): void
    {
        $this->assertSame(
            $this->scriptTag().'<button type="button" class="kolossus-chat-button" data-kolossus="'.self::TOKEN.'" data-kolossus-open="remember">Chat with us</button>',
            Blade::render('@kolossusChatButton')
        );
        $html = Blade::render('@kolossusChatButton(\'Ask <us>\', \'btn  btn-primary" onclick=x\')');
        $this->assertStringContainsString('class="kolossus-chat-button btn btn-primary onclickx"', $html);
        $this->assertStringContainsString('>Ask &lt;us&gt;</button>', $html);
    }

    public function test_script_is_emitted_once_per_request(): void
    {
        $html = Blade::render('@kolossusChatScript @kolossusChat @kolossusChatButton(\'A\') @kolossusChatButton(\'B\')');
        $this->assertSame(1, substr_count($html, 'widget.js'));
        $this->assertSame(2, substr_count($html, '<button'));
        $this->assertSame(1, substr_count($html, 'hidden aria-hidden'));
    }

    public function test_directives_render_nothing_when_not_configured(): void
    {
        config(['kolossus-chat.widget_token' => '']);
        $this->assertSame('', trim(Blade::render('@kolossusChat @kolossusChatButton(\'A\') @kolossusChatScript')));
    }

    public function test_label_can_come_from_a_variable(): void
    {
        $this->assertStringContainsString('>Hi Ann</button>', Blade::render('@kolossusChatButton("Hi " . $name)', ['name' => 'Ann']));
    }

    public function test_any_label_or_class_value_renders_without_errors(): void
    {
        $html = Blade::render('@kolossusChatButton($label, $class)', ['label' => new HtmlString('<b>Ask</b>'), 'class' => ['x']]);
        $this->assertStringContainsString('class="kolossus-chat-button" data-kolossus=', $html);
        $this->assertStringContainsString('>&lt;b&gt;Ask&lt;/b&gt;</button>', $html);

        $this->assertStringContainsString('>42</button>', Blade::render('@kolossusChatButton(42)'));
        $this->assertStringContainsString('"></button>', Blade::render('@kolossusChatButton($l)', ['l' => ['a']]));
        $this->assertStringContainsString('"></button>', Blade::render('@kolossusChatButton($l)', ['l' => new \stdClass()]));
    }

    public function test_broken_views_never_break_the_page(): void
    {
        View::replaceNamespace('kolossus-chat', [__DIR__.'/views/broken']);
        Log::spy();
        $html = Blade::render('<p>a</p> @kolossusChat @kolossusChatButton(\'x\') @kolossusChatScript <p>b</p>');
        $this->assertSame('<p>a</p><p>b</p>', preg_replace('/\s+/', '', $html));
        // The widget and the button fail; the script then counts as sent already.
        Log::shouldHaveReceived('error')->twice();
    }

    public function test_csp_nonce_is_added_when_the_site_uses_one(): void
    {
        Vite::useCspNonce('n0nce"x');
        $this->assertStringStartsWith(
            '<script src="'.self::HOST.'/static/widget/widget.js" async nonce="n0nce&quot;x"></script>',
            Blade::render('@kolossusChat')
        );
    }
}
