<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    private const PAGES = [
        '/' => 'index.html',
        '/sign-in' => 'pages/client/sign-in.html',
        '/sign-up' => 'pages/client/signup.html',
        '/forgot-password' => 'pages/client/forgot-password.html',
        '/verify-email' => 'pages/client/verify-email.html',
        '/set-up-password' => 'pages/client/set-up-password.html',
        '/confirm-email-recovery' => 'pages/client/confirm-email-recovery.html',
        '/terms-and-conditions' => 'pages/legal/terms-and-conditions.html',
        '/privacy-policy' => 'pages/legal/privacy-policy.html',
        '/account' => 'pages/client/dashboard.html',
        '/account/pets' => 'pages/client/my-pets.html',
        '/account/pets/details' => 'pages/client/pet-details.html',
        '/account/grooming-history' => 'pages/client/grooming-history.html',
        '/account/notifications' => 'pages/client/notifications.html',
        '/account/settings' => 'pages/client/settings.html',
        '/account/registration' => 'pages/client/pre-register.html',
        '/account/registration/grooming' => 'pages/client/grooming-pre-registration.html',
        '/account/registration/clinic/pets' => 'pages/client/booking-pet-details.html',
        '/account/registration/clinic/reason' => 'pages/client/clinic-visit-reason.html',
        '/account/registration/clinic/review' => 'pages/client/clinic-visit-summary.html',
        '/account/registration/clinic/confirmed' => 'pages/client/clinic-visit-confirmed.html',
        '/admin/dashboard' => 'pages/admin/dashboard.html',
        '/admin/customers' => 'pages/admin/clients.html',
        '/admin/grooming' => 'pages/admin/appointments.html',
        '/admin/clinic' => 'pages/admin/clinic.html',
        '/admin/inventory' => 'pages/admin/inventory/inventory.html',
        '/admin/history' => 'pages/admin/archive.html',
        '/admin/transactions' => 'pages/admin/transactions.html',
        '/admin/reports' => 'pages/admin/reports.html',
        '/admin/services' => 'pages/admin/services.html',
        '/admin/settings' => 'pages/admin/settings.html',
        '/admin/notifications' => 'pages/admin/notifications.html',
        '/admin/activity-log' => 'pages/admin/activity-log.html',
        '/admin/availability' => 'pages/admin/availability.html',
        '/admin/walk-in/customer' => 'pages/admin/walk-in-booking.html',
        '/admin/walk-in/pets' => 'pages/admin/walk-in-pet-details.html',
        '/admin/walk-in/grooming/services' => 'pages/admin/walk-in-grooming-services.html',
        '/admin/walk-in/grooming/review' => 'pages/admin/walk-in-booking-review.html',
        '/admin/walk-in/grooming/consent' => 'pages/admin/walk-in-consent.html',
        '/admin/walk-in/grooming/confirmed' => 'pages/admin/walk-in-booking-confirmed.html',
        '/admin/walk-in/clinic/confirmed' => 'pages/admin/walk-in-clinic-confirmed.html',
    ];

    public function test_canonical_documents_and_fragments_load_without_database_or_sessions(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) { $queries[] = $query->sql; });
        foreach (self::PAGES as $url => $file) {
            if (in_array($url, ['/admin/activity-log', '/admin/availability'])) continue;
            $response = $this->get($url)->assertOk()->assertHeader('Cache-Control');
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'), $url);
            $this->assertSame(realpath(base_path($file)), $response->baseResponse->getFile()->getRealPath(), $url);
            $this->assertSame([], $response->headers->getCookies(), $url);
        }
        foreach (['/pages/admin/pet-edit-form.html', '/pages/admin/clinic/sections/services.html',
            '/pages/admin/services/sections/grooming.html', '/pages/admin/inventory/sections/products.html',
            '/pages/admin/inventory/sections/sell-product.html'] as $fragment) {
            $this->get($fragment)->assertOk();
        }
        $this->get('/scripts/api.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');
        $this->assertSame([], $queries);
    }

    public function test_document_resources_and_navigation_resolve_from_canonical_urls(): void
    {
        $resources = [];
        foreach (self::PAGES as $url => $file) {
            if ($url === '/admin/activity-log') $file = 'resources/views/admin/activity-log.html';
            if ($url === '/admin/availability') $file = 'resources/views/admin/availability.html';
            $dom = new \DOMDocument;
            $dom->loadHTML(file_get_contents(base_path($file)), LIBXML_NOERROR | LIBXML_NOWARNING);
            foreach ((new \DOMXPath($dom))->query('//*[@src or @href]') as $node) {
                foreach (['src', 'href'] as $attribute) {
                    $value = $node->getAttribute($attribute);
                    if (!$value || preg_match('~^(?:https?:|data:|mailto:|tel:|#)~', $value)) continue;
                    $this->assertStringStartsWith('/', $value, $url.' '.$value);
                    $path = parse_url($value, PHP_URL_PATH);
                    if (in_array($node->tagName, ['a', 'area'])) {
                        $this->assertArrayHasKey($path, self::PAGES, $url.' '.$value);
                    } elseif ($path !== '/favicon.ico') $resources[$path] = true;
                }
            }
        }
        foreach (array_keys($resources) as $resource) $this->get($resource)->assertOk();
        $this->withUnencryptedCookie('bethlehem_account_session', 'existing-session')->withCredentials()
            ->get('/scripts/api.js')->assertOk()->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    public function test_legacy_browser_pages_redirect_permanently_with_queries(): void
    {
        foreach (self::PAGES as $url => $file) {
            if ($file === 'pages/client/booking-pet-details.html') $url = '/account/registration/grooming';
            $this->get('/'.$file.'?selected_id=42&return_to=%2Fadmin%2Finventory%23products')
                ->assertStatus(301)->assertRedirect($url.'?selected_id=42&return_to=%2Fadmin%2Finventory%23products');
        }
        $this->get('/pages/client/booking-pet-details.html?flow=clinic&pet_id=4')->assertStatus(301)
            ->assertRedirect('/account/registration/clinic/pets?flow=clinic&pet_id=4');
        $this->get('/pages/admin/inventory/pos.html?payment=complete')->assertStatus(301)
            ->assertRedirect('/admin/inventory?payment=complete#sell-product');
        $this->get('/pages/client/clinic-visit-date.html?pet_id=4')->assertStatus(301)
            ->assertRedirect('/account/registration/clinic/pets?flow=clinic&pet_id=4');
    }

    public function test_undefined_pages_and_private_files_are_not_served_by_the_frontend_router(): void
    {
        foreach (['/account/unknown', '/admin/unknown', '/pages/client/unknown.html', '/routes/web.php',
            '/resources/views/admin/activity-log.html', '/pages/../routes/web.php', '/pages/admin/unused.html'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }
}
