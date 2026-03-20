<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

class ZengaPayConfigTest extends TestCase
{
    public function test_callback_url_falls_back_to_app_url_when_env_uses_placeholder_literal(): void
    {
        $originalAppUrl = getenv('APP_URL');
        $originalCallbackUrl = getenv('ZENGAPAY_CALLBACK_URL');

        putenv('APP_URL=https://api.example.test');
        putenv('ZENGAPAY_CALLBACK_URL=${APP_URL}/api/webhooks/zengapay');
        $_ENV['APP_URL'] = 'https://api.example.test';
        $_SERVER['APP_URL'] = 'https://api.example.test';
        $_ENV['ZENGAPAY_CALLBACK_URL'] = '${APP_URL}/api/webhooks/zengapay';
        $_SERVER['ZENGAPAY_CALLBACK_URL'] = '${APP_URL}/api/webhooks/zengapay';

        $config = require base_path('config/services.php');

        $this->assertSame(
            'https://api.example.test/api/webhooks/zengapay',
            $config['zengapay']['callback_url']
        );

        if ($originalAppUrl === false) {
            putenv('APP_URL');
            unset($_ENV['APP_URL'], $_SERVER['APP_URL']);
        } else {
            putenv("APP_URL={$originalAppUrl}");
            $_ENV['APP_URL'] = $originalAppUrl;
            $_SERVER['APP_URL'] = $originalAppUrl;
        }

        if ($originalCallbackUrl === false) {
            putenv('ZENGAPAY_CALLBACK_URL');
            unset($_ENV['ZENGAPAY_CALLBACK_URL'], $_SERVER['ZENGAPAY_CALLBACK_URL']);
        } else {
            putenv("ZENGAPAY_CALLBACK_URL={$originalCallbackUrl}");
            $_ENV['ZENGAPAY_CALLBACK_URL'] = $originalCallbackUrl;
            $_SERVER['ZENGAPAY_CALLBACK_URL'] = $originalCallbackUrl;
        }
    }
}
