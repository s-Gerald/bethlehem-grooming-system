<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicClinicInformationTest extends TestCase
{
    private array $information = [
        'primary_contact_number' => '7007-3122',
        'secondary_contact_number' => '0917 113 1941',
        'clinic_address' => 'K20 Ortigas Avenue Extension, Pearl Ave. St., Ortigas, Greenheights Subd., Brgy. San Isidro, Taytay, Rizal, Taytay, Philippines, 1920',
        'map_directions_url' => 'https://www.bing.com/maps/search?v=2&pc=FACEBK&mid=8100&name=K20+Ortigas+Avenue+Extension%2C+Pearl+Ave.+St.%2C+Ortigas%2C+Greenheights+Subd.%2C+Brgy.+San+Isidro%2C+++Taytay%2C+Rizal%2C+Taytay%2C+Philippines%2C+1920&mkt=en-US&FORM=FBKPL1&q=14.57937%2C+121.13774&cp=14.579154%7E121.137903&lvl=18.8&style=r',
        'facebook_url' => 'https://www.facebook.com/2022BethlehemAC',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        (require database_path('migrations/2026_07_04_160000_create_clinic_settings_table.php'))->up();
        Schema::table('clinic_settings', function (Blueprint $table) {
            $table->string('clinic_open_time')->default('08:00:00');
            $table->unsignedInteger('grooming_cage_capacity')->default(20);
        });
        (require database_path('migrations/2026_11_17_000001_add_public_information_to_clinic_settings.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('clinic_settings');
        parent::tearDown();
    }

    public function test_public_information_is_unauthenticated_and_contains_only_the_five_public_fields(): void
    {
        $this->getJson('/api/public/clinic-information')->assertOk()->assertExactJson(['data' => $this->information]);
    }

    public function test_migration_defaults_match_real_html_fallbacks_and_rollback_preserves_operational_data(): void
    {
        $document = new \DOMDocument();
        @$document->loadHTML(file_get_contents(base_path('index.html')));
        $links = new \DOMXPath($document);
        $address = $links->query('//a[starts-with(@href, "https://www.bing.com/maps/search?v=2")]')->item(0);
        $this->assertSame($this->information['clinic_address'], $address->textContent);
        $this->assertSame($this->information['map_directions_url'], $address->getAttribute('href'));
        DB::table('clinic_settings')->where('id', 1)->update(['grooming_cage_capacity' => 35]);
        $migration = require database_path('migrations/2026_11_17_000001_add_public_information_to_clinic_settings.php');
        $migration->down();
        $this->assertDatabaseHas('clinic_settings', ['id' => 1, 'grooming_cage_capacity' => 35]);
        $migration->up();
        $this->assertDatabaseHas('clinic_settings', [...$this->information, 'id' => 1, 'grooming_cage_capacity' => 35]);
    }

    public function test_admin_and_each_staff_role_can_read_general_settings(): void
    {
        foreach (['admin', 'grooming_receptionist', 'clinic_receptionist', 'veterinarian'] as $role) {
            Sanctum::actingAs(new User(['role' => $role === 'admin' ? 'admin' : 'staff', 'staff_subrole' => $role]));
            $this->getJson('/api/settings/general')->assertOk()->assertExactJson(['data' => $this->information]);
        }
    }

    public function test_admin_updates_are_persisted_publicly_without_changing_operational_settings(): void
    {
        Sanctum::actingAs(new User(['role' => 'admin']));
        $changed = [...$this->information, 'primary_contact_number' => '+63 (2) 7007-3123',
            'secondary_contact_number' => '0917 113 1942', 'clinic_address' => 'Updated clinic address',
            'map_directions_url' => 'https://www.bing.com/maps/search?q=updated', 'facebook_url' => 'https://www.facebook.com/updated'];
        $this->patchJson('/api/settings/general', [...$changed, 'grooming_cage_capacity' => 1, 'clinic_open_time' => '01:00:00'])
            ->assertOk()->assertJsonPath('data', $changed);
        $this->getJson('/api/public/clinic-information')->assertOk()->assertExactJson(['data' => $changed]);
        $this->assertDatabaseHas('clinic_settings', [...$changed, 'id' => 1, 'grooming_cage_capacity' => 20, 'clinic_open_time' => '08:00:00']);
        $this->assertDatabaseCount('clinic_settings', 1);
    }

    public function test_staff_direct_update_attempts_are_forbidden_and_do_not_change_data(): void
    {
        foreach (['grooming_receptionist', 'clinic_receptionist', 'veterinarian'] as $role) {
            Sanctum::actingAs(new User(['role' => 'staff', 'staff_subrole' => $role]));
            $this->patchJson('/api/settings/general', [...$this->information, 'clinic_address' => 'Unauthorized change'])->assertForbidden();
        }
        $this->assertDatabaseHas('clinic_settings', $this->information);
    }

    public function test_general_requires_authentication_and_rejects_customers(): void
    {
        $this->getJson('/api/settings/general')->assertUnauthorized();
        $this->patchJson('/api/settings/general', $this->information)->assertUnauthorized();
        Sanctum::actingAs(new User(['role' => 'customer']));
        $this->getJson('/api/settings/general')->assertForbidden();
        $this->patchJson('/api/settings/general', $this->information)->assertForbidden();
    }

    public function test_invalid_information_is_rejected_without_partial_saves(): void
    {
        Sanctum::actingAs(new User(['role' => 'admin']));
        foreach ([
            ['primary_contact_number' => '   ', 'secondary_contact_number' => '', 'clinic_address' => '',
                'map_directions_url' => 'javascript:alert(1)', 'facebook_url' => 'ftp://facebook.com/page'],
            ['primary_contact_number' => str_repeat('1', 51), 'secondary_contact_number' => str_repeat('2', 51),
                'clinic_address' => str_repeat('a', 501), 'map_directions_url' => 'https://example.com/'.str_repeat('a', 2048),
                'facebook_url' => 'https://example.com/'.str_repeat('b', 2048)],
            array_fill_keys(array_keys($this->information), ['invalid']),
        ] as $invalid) {
            $this->patchJson('/api/settings/general', $invalid)->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
            $this->assertDatabaseHas('clinic_settings', $this->information);
        }
    }
}
