<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAuthenticationSchema
{
    private function createAuthenticationSchema(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['api.pwnedpasswords.com/*' => \Illuminate\Support\Facades\Http::response(str_repeat('A', 35).':0')]);

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('username', 50)->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('email', 150)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('email_verification_token', 64)->nullable()->unique();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->string('password_hash');
            $table->string('role')->default('customer');
            $table->string('customer_tier')->default('new');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
        });

        Schema::create('pending_customer_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('username', 50)->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('email', 150)->unique();
            $table->string('password_hash');
            $table->string('email_verification_token', 64)->nullable()->unique();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('login_email_challenges', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->string('poll_token_hash', 64)->nullable()->unique();
            $table->boolean('remember_me')->default(true);
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable()->index();
            $table->timestamps();
        });
        (require base_path('database/migrations/2026_10_30_000001_isolate_login_email_challenges.php'))->up();
        (require base_path('database/migrations/2026_10_30_000002_create_account_security_incidents.php'))->up();

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tokenable_type', 'tokenable_id']);
        });
        (require base_path('database/migrations/2026_10_18_000001_add_token_session_state.php'))->up();
        (require base_path('database/migrations/2026_10_25_000001_add_session_replacement.php'))->up();
        (require base_path('database/migrations/2026_10_29_000003_create_remembered_devices.php'))->up();
        (require base_path('database/migrations/2026_10_05_000001_add_registration_verification_codes.php'))->up();
        (require base_path('database/migrations/2026_10_22_000001_add_registration_recovery_grants.php'))->up();
        (require base_path('database/migrations/2026_10_28_000002_add_registration_browser_binding.php'))->up();
        (require base_path('database/migrations/2026_11_01_000001_add_customer_terms_acceptance.php'))->up();
        (require base_path('database/migrations/2026_11_04_000001_add_registration_pairing.php'))->up();
        (require base_path('database/migrations/2026_11_07_000001_make_customer_registration_verify_first.php'))->up();
    }

    private function dropAuthenticationSchema(): void
    {
        Schema::dropIfExists('account_security_incidents');
        Schema::dropIfExists('remembered_device_credentials');
        Schema::dropIfExists('remembered_devices');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('login_email_challenges');
        Schema::dropIfExists('pending_customer_registrations');
        Schema::dropIfExists('users');

    }


}
