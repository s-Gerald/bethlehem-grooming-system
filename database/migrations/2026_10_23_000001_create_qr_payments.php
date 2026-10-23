<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL commits separately, so a failed run may leave settings behind.
        if (! Schema::hasTable('qr_payment_settings')) {
            Schema::create('qr_payment_settings', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->boolean('enabled')->default(false);
                $table->unsignedInteger('version')->default(1);
                $table->string('merchant_name', 150)->nullable();
                $table->string('provider_name', 100)->nullable();
                $table->string('image_path')->nullable();
                $table->char('image_hash', 64)->nullable();
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }
        if (! DB::table('qr_payment_settings')->where('id', 1)->exists()) {
            DB::table('qr_payment_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        if (! Schema::hasTable('qr_payments')) {
            Schema::create('qr_payments', function (Blueprint $table) {
                $table->id();
                $table->morphs('payable');
                $table->unique(['payable_type', 'payable_id']);
                $table->string('external_reference', 80)->nullable()->unique();
                $table->decimal('received_amount', 10, 2);
                // Both dates are supplied at settlement; avoid legacy TIMESTAMP defaults/updates.
                $table->dateTime('transaction_at');
                $table->string('verification_source', 30);
                $table->unsignedInteger('verified_by');
                $table->string('verified_by_name', 150);
                $table->dateTime('verified_at');
                $table->text('reference_unavailable_reason')->nullable();
                $table->unsignedInteger('settings_version');
                $table->string('merchant_name', 150);
                $table->string('provider_name', 100);
                $table->char('qr_image_hash', 64);
            });
        }
        if (! Schema::hasColumn('payments', 'qr_payment_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedBigInteger('qr_payment_id')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['qr_payment_id']);
            $table->dropColumn('qr_payment_id');
        });
        Schema::dropIfExists('qr_payments');
        Schema::dropIfExists('qr_payment_settings');
    }
};
