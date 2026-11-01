<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['bookings', 'clinic_appointments'] as $table) {
            // Preserve every existing enum member and add the neutral terminal status.
            if (DB::getDriverName() === 'mysql') {
                $column = DB::selectOne("SHOW COLUMNS FROM {$table} WHERE Field = 'status'");
                if (str_starts_with($column->Type, 'enum(') && ! str_contains($column->Type, "'expired'")) {
                    $type = substr($column->Type, 0, -1).",'expired')";
                    $default = DB::getPdo()->quote($column->Default);
                    DB::statement("ALTER TABLE {$table} MODIFY status {$type} NOT NULL DEFAULT {$default}");
                }
            } else {
                // Laravel's SQLite enum is a varchar with a CHECK constraint.
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint
                    ->string('status')->default($table === 'bookings' ? 'waiting_to_arrive' : 'checked_in')->change());
            }

            if (! Schema::hasColumn($table, 'legacy_pre_registration_status')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint
                    ->string('legacy_pre_registration_status')->nullable());
            }

            $checkIn = $table === 'bookings' ? 'dropped_off_at' : 'checked_in_at';
            // Normalize only unused registrations; keep all linked history and audit the old status.
            DB::table($table)->where('status', 'no_show')->whereNull($checkIn)->update([
                'status' => 'expired',
                'legacy_pre_registration_status' => 'no_show',
            ]);
        }

        if (! Schema::hasColumn('customer_notifications', 'clinic_appointment_id')) {
            Schema::table('customer_notifications', function (Blueprint $table) {
                $table->foreignId('clinic_appointment_id')->nullable()
                    ->constrained('clinic_appointments')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Keep expanded statuses and audit information on rollback: reverting
        // expired records to an active or punitive state would corrupt history.
    }
};
