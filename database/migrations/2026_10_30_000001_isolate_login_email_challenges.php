<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $expiry = DB::selectOne("SHOW COLUMNS FROM `login_email_challenges` LIKE 'expires_at'");
            if (str_contains(strtolower((string) $expiry->Extra), 'on update')) {
                // Earlier deployed schemas may have MySQL's implicit first-
                // TIMESTAMP behavior. Counter/receipt updates must not expire OTPs.
                Schema::table('login_email_challenges', fn (Blueprint $table) =>
                    $table->timestamp('expires_at')->useCurrent()->change());
            }
        }
        // Add the replacement index before dropping the MySQL foreign key's
        // supporting unique index. Each step also tolerates a partial DDL run.
        if (! Schema::hasIndex('login_email_challenges', 'login_challenges_user_expiry')) {
            Schema::table('login_email_challenges', fn (Blueprint $table) =>
                $table->index(['user_id', 'expires_at'], 'login_challenges_user_expiry'));
        }
        if (Schema::hasIndex('login_email_challenges', 'login_email_challenges_user_id_unique')) {
            Schema::table('login_email_challenges', fn (Blueprint $table) =>
                $table->dropUnique('login_email_challenges_user_id_unique'));
        }
        foreach (['failed_attempts', 'source_hash', 'last_sent_at', 'delivery_receipt'] as $column) {
            if (Schema::hasColumn('login_email_challenges', $column)) continue;
            Schema::table('login_email_challenges', function (Blueprint $table) use ($column): void {
                match ($column) {
                    'failed_attempts' => $table->unsignedSmallInteger($column)->default(0),
                    'source_hash' => $table->string($column, 64)->nullable(),
                    'last_sent_at' => $table->timestamp($column)->nullable(),
                    'delivery_receipt' => $table->text($column)->nullable(),
                };
            });
        }
        DB::table('login_email_challenges')->whereNull('last_sent_at')->update(['last_sent_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        // Forward-only: restoring account uniqueness would discard valid sessions.
    }
};
