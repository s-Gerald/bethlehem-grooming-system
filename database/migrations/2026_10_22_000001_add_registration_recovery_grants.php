<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RECOVERY_INDEXES = [
        'users' => 'users_registration_recovery_token_hash_unique',
        'pending_customer_registrations' => 'pending_reg_recovery_token_uq',
    ];

    public function up(): void
    {
        foreach (self::RECOVERY_INDEXES as $name => $index) {
            if (! Schema::hasColumn($name, 'registration_recovery_token_hash')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->string('registration_recovery_token_hash', 64)->nullable();
                });
            }
            if (! Schema::hasIndex($name, ['registration_recovery_token_hash'], 'unique')) {
                Schema::table($name, function (Blueprint $table) use ($index) {
                    $table->unique('registration_recovery_token_hash', $index);
                });
            }
            if (! Schema::hasColumn($name, 'registration_recovery_expires_at')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->timestamp('registration_recovery_expires_at')->nullable();
                });
            }
        }
        if (! Schema::hasIndex('pending_customer_registrations', 'pending_registration_created_idx')) {
            Schema::table('pending_customer_registrations', function (Blueprint $table) {
                $table->index('created_at', 'pending_registration_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('pending_customer_registrations', function (Blueprint $table) {
            $table->dropIndex('pending_registration_created_idx');
        });
        foreach (self::RECOVERY_INDEXES as $name => $index) {
            Schema::table($name, function (Blueprint $table) use ($index) {
                $table->dropUnique($index);
                $table->dropColumn(['registration_recovery_token_hash', 'registration_recovery_expires_at']);
            });
        }
    }
};
