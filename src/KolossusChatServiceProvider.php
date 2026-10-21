<?php

namespace KolossusAI\Chat;

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use KolossusAI\Chat\Console\InstallCommand;
use KolossusAI\Chat\Http\Middleware\InjectChatWidget;

/**
 * Found by Laravel's package discovery, so `composer require` is enough to load it.
 *
 * Blade directives:
 *     @kolossusChat                                 site-wide chat bubble
 *     @kolossusChatButton('Chat with us', 'btn')    a button that opens the chat
 *     @kolossusChatScript                           only the widget script
 */
class KolossusChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/kolossus-chat.php', 'kolossus-chat');
        $this->app->singleton(KolossusChat::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'kolossus-chat');

        $chat = '\\'.KolossusChat::class;
        Blade::directive('kolossusChat', fn () => "<?php echo app({$chat}::class)->widget(); ?>");
        Blade::directive('kolossusChatButton', fn ($expression) => "<?php echo app({$chat}::class)->button({$expression}); ?>");
        Blade::directive('kolossusChatScript', fn () => "<?php echo app({$chat}::class)->script(); ?>");

        $this->addMiddleware();

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
            $this->publishes([
                __DIR__.'/../config/kolossus-chat.php' => config_path('kolossus-chat.php'),
            ], 'kolossus-chat-config');
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/kolossus-chat'),
            ], 'kolossus-chat-views');
        }
    }

    /**
     * InjectChatWidget goes last in the "web" group, so the session and the logged-in
     * user are known. It checks by itself that the chat is configured and sitewide is on.
     *
     * Added through the HTTP kernel: from Laravel 11 the kernel copies its own groups
     * (from bootstrap/app.php) over the router's when it is created, which would drop
     * a middleware pushed onto the router only.
     */
    private function addMiddleware(): void
    {
        $kernel = $this->app->bound(HttpKernelContract::class) ? $this->app->make(HttpKernelContract::class) : null;
        if ($kernel instanceof HttpKernel && array_key_exists('web', $kernel->getMiddlewareGroups())) {
            $kernel->appendMiddlewareToGroup('web', InjectChatWidget::class);

            return;
        }
        $this->callAfterResolving('router', function (Router $router) {
            $router->pushMiddlewareToGroup('web', InjectChatWidget::class);
        });
    }
}
