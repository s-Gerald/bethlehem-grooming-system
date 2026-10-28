<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerNameUpdateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            foreach (['first_name', 'last_name', 'email', 'password_hash', 'role'] as $field) $table->string($field);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('email_verified_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    private function customer(string $role = 'customer'): User
    {
        return User::create(['first_name' => 'Old', 'last_name' => 'Name', 'email' => 'customer@gmail.com',
            'password_hash' => Hash::make('CurrentPassword!123'), 'role' => $role, 'email_verified_at' => now()]);
    }

    private function payload(): array
    {
        return ['first_name' => 'gerald', 'last_name' => 'de la Cruz', 'current_password' => 'CurrentPassword!123'];
    }

    public function test_customer_can_change_only_their_names_with_password_and_without_otp(): void
    {
        Notification::fake();
        $user = $this->customer();
        $other = $this->customer();
        Sanctum::actingAs($user);
        $this->patchJson('/api/customer/profile/name', $this->payload())->assertOk()
            ->assertJsonPath('user.first_name', 'Gerald')->assertJsonPath('user.last_name', 'de la Cruz')
            ->assertJsonMissingPath('user.password_hash');
        $this->assertSame('Gerald', $user->fresh()->first_name);
        $this->assertSame('Old', $other->fresh()->first_name);
        $this->assertTrue(Hash::check('CurrentPassword!123', $user->fresh()->password_hash));
        Notification::assertNothingSent();
    }

    public function test_wrong_missing_password_and_invalid_names_do_not_update_any_names(): void
    {
        $user = $this->customer(); Sanctum::actingAs($user);
        $this->patchJson('/api/customer/profile/name', [...$this->payload(), 'current_password' => 'wrong'])
            ->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'Incorrect password.');
        $this->patchJson('/api/customer/profile/name', ['first_name' => 'Gerald', 'last_name' => 'McDonald'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/customer/profile/name', [...$this->payload(), 'first_name' => 'Gerald２'])
            ->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->assertSame('Old', $user->fresh()->first_name);
    }

    public function test_unrelated_and_cross_account_fields_are_rejected(): void
    {
        $user = $this->customer(); Sanctum::actingAs($user);
        foreach (['user_id' => 2, 'email' => 'other@gmail.com', 'role' => 'admin', 'phone' => '09171111111'] as $field => $value) {
            $this->patchJson('/api/customer/profile/name', [...$this->payload(), $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame('Old', $user->fresh()->first_name);
    }

    public function test_unauthenticated_and_staff_requests_are_rejected(): void
    {
        $this->patchJson('/api/customer/profile/name', $this->payload())->assertUnauthorized();
        Sanctum::actingAs($this->customer('admin'));
        $this->patchJson('/api/customer/profile/name', $this->payload())->assertForbidden();
    }

    public function test_unchanged_historical_names_are_not_recased(): void
    {
        $user = $this->customer(); $user->update(['first_name' => 'GERALD', 'last_name' => 'McDonald']);
        Sanctum::actingAs($user);
        $this->patchJson('/api/customer/profile/name', [...$this->payload(), 'first_name' => 'GERALD', 'last_name' => 'McDonald'])
            ->assertOk()->assertJsonPath('user.first_name', 'GERALD')->assertJsonPath('user.last_name', 'McDonald');
    }

    public function test_unusable_accounts_cannot_change_names(): void
    {
        foreach ([['is_active' => false], ['is_archived' => true], ['email_verified_at' => null]] as $status) {
            $user = $this->customer(); $user->update($status); Sanctum::actingAs($user);
            $this->patchJson('/api/customer/profile/name', $this->payload())->assertForbidden();
            $this->assertSame('Old', $user->fresh()->first_name);
        }
    }
}
