<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_legal_documents_and_their_assets_are_public_without_a_session_or_database_work(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) { $queries[] = $query->sql; });

        foreach (['/terms-and-conditions', '/privacy-policy',
            '/css/legal.css', '/assets/images/clinic/Bethlehem_Logo-256.png'] as $path) {
            $response = $this->get($path)->assertOk();
            $this->assertSame([], $response->headers->getCookies());
        }

        $this->assertSame([], $queries);
    }
}
