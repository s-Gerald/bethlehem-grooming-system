<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected $defaultHeaders = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function withAccountSession(\Illuminate\Testing\TestResponse $response): static
    {
        $cookie = $response->getCookie('bethlehem_account_session', false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(explode('|', $cookie->getValue())[0].'|cookie', $response->json('token'));
        $this->app['auth']->forgetGuards();

        return $this->withUnencryptedCookie('bethlehem_account_session', $cookie->getValue())
            ->withToken($response->json('token'))->withCredentials();
    }
}
