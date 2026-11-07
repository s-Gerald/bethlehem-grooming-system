<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'id', 'email', 'registration_edit_token_hash', 'registration_browser_token_hash',
        'email_verification_token', 'email_verification_expires_at', 'email_verification_last_sent_at',
        'registration_approved_at', 'registration_completed_at', 'completed_session_id', 'created_at', 'updated_at',
    ];

    private const INDEXES = [
        'pending_reg_email_idx' => ['email', false],
        'pending_reg_edit_token_uq' => ['registration_edit_token_hash', true],
        'pending_reg_email_token_uq' => ['email_verification_token', true],
        'pending_registration_created_idx' => ['created_at', false],
    ];

    public function up(): void
    {
        // Only disposable signup attempts are invalidated. Deploy in maintenance.
        // MySQL can commit the table before failing to create its indexes.
        if ($this->hasCompleteSchema()) return;

        Schema::dropIfExists('pending_customer_registrations');
        Schema::create('pending_customer_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150);
            $table->string('registration_edit_token_hash', 64);
            $table->string('registration_browser_token_hash', 64);
            $table->string('email_verification_token', 64)->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('email_verification_last_sent_at')->nullable();
            $table->timestamp('registration_approved_at')->nullable();
            $table->timestamp('registration_completed_at')->nullable();
            $table->unsignedBigInteger('completed_session_id')->nullable();
            $table->timestamps();
            foreach (self::INDEXES as $name => [$column, $unique]) {
                if ($unique) $table->unique($column, $name);
                else $table->index($column, $name);
            }
        });
    }

    private function hasCompleteSchema(): bool
    {
        if (! Schema::hasTable('pending_customer_registrations')) return false;
        $columns = Schema::getColumnListing('pending_customer_registrations');
        if (count($columns) !== count(self::COLUMNS) || array_diff(self::COLUMNS, $columns)) return false;

        $indexes = Schema::getIndexes('pending_customer_registrations');
        if (count($indexes) !== count(self::INDEXES) + 1) return false;
        $primary = array_filter($indexes, fn ($index) => $index['primary'] && $index['columns'] === ['id'] && $index['unique']);
        if (count($primary) !== 1) return false;
        $byName = array_column($indexes, null, 'name');
        foreach (self::INDEXES as $name => [$column, $unique]) {
            $index = $byName[$name] ?? null;
            if (! $index || $index['columns'] !== [$column] || $index['unique'] !== $unique) return false;
        }
        return true;
    }

    public function down(): void
    {
        // Rollback invalidates attempts too; credentials cannot be reconstructed.
        Schema::dropIfExists('pending_customer_registrations');
        (require __DIR__.'/2026_08_18_000001_create_pending_customer_registrations_table.php')->up();
        Schema::table('pending_customer_registrations', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
            $table->string('email_verification_code_hash')->nullable();
            $table->unsignedTinyInteger('email_verification_attempts')->default(0);
            $table->timestamp('email_verification_last_sent_at')->nullable();
            $table->string('registration_edit_token_hash', 64)->nullable()->unique('pending_registration_edit_token_unique');
            $table->string('registration_browser_token_hash', 64)->nullable();
            $table->string('registration_recovery_token_hash', 64)->nullable()->unique('pending_reg_recovery_token_uq');
            $table->timestamp('registration_recovery_expires_at')->nullable();
            $table->string('terms_accepted_version', 32)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->index('created_at', 'pending_registration_created_idx');
        });
        (require __DIR__.'/2026_11_04_000001_add_registration_pairing.php')->up();
    }
};
