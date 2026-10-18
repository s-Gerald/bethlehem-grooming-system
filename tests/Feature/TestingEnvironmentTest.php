<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestingEnvironmentTest extends TestCase
{
    public function test_regressions_use_isolated_non_sending_configuration(): void
    {
        $this->assertSame('testing', $this->app->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('queued', config('mail.authentication.delivery'));
        $this->assertSame('', config('mail.authentication.queue'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertFalse($this->app->configurationIsCached());
    }
}
