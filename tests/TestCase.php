<?php

namespace KolossusAI\Chat\Tests;

use KolossusAI\Chat\KolossusChatServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const TOKEN = 'wt_abcdefgh12345678';

    protected const SECRET = 'ws_secret12345678';

    protected const HOST = 'https://chat.example.com';

    protected function getPackageProviders($app): array
    {
        return [KolossusChatServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('kolossus-chat.host_url', self::HOST);
        $app['config']->set('kolossus-chat.widget_token', self::TOKEN);
    }

    protected function scriptTag(): string
    {
        return '<script src="'.self::HOST.'/static/widget/widget.js" async></script>';
    }
}
