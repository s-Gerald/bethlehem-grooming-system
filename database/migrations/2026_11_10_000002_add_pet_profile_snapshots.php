<?php

use App\Support\PetProfileSnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['booking_pets' => 'booking_pet_id', 'clinic_appointments' => 'id'] as $table => $key) {
            if (! Schema::hasTable($table)) continue;
            if (! Schema::hasColumn($table, 'pet_profile_snapshot')) {
                Schema::table($table, fn (Blueprint $t) => $t->json('pet_profile_snapshot')->nullable());
            }

            $hasPets = Schema::hasTable('pets');
            $fields = $hasPets ? array_intersect(PetProfileSnapshot::FIELDS, Schema::getColumnListing('pets')) : [];
            $query = DB::table("{$table} as sessions")->whereNull('sessions.pet_profile_snapshot')
                ->select("sessions.{$key} as snapshot_id");
            if ($hasPets) {
                $query->leftJoin('pets as profiles', 'profiles.pet_id', '=', 'sessions.pet_id');
                foreach ($fields as $field) $query->addSelect("profiles.{$field} as profile_{$field}");
            }
            $sizeFields = $table === 'booking_pets'
                ? array_intersect(['registered_size', 'confirmed_size'], Schema::getColumnListing($table)) : [];
            foreach ($sizeFields as $field) $query->addSelect("sessions.{$field}");

            // One joined read per bounded chunk; conditional writes make retries safe.
            $query->chunkById(200, function ($rows) use ($table, $key, $fields, $sizeFields) {
                foreach ($rows as $row) {
                    $profile = [];
                    foreach ($fields as $field) $profile[$field] = $row->{"profile_{$field}"};
                    if ($sizeFields !== []) $profile['size'] = $row->confirmed_size ?? $row->registered_size ?? $profile['size'] ?? null;
                    $snapshot = ['version' => 1, 'legacy' => PetProfileSnapshot::capture($profile, 'legacy_baseline')];
                    DB::table($table)->where($key, $row->snapshot_id)->whereNull('pet_profile_snapshot')
                        ->update(['pet_profile_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
                }
            }, "sessions.{$key}", 'snapshot_id');
        }
    }

    public function down(): void
    {
        foreach (['booking_pets', 'clinic_appointments'] as $table) {
            if (Schema::hasColumn($table, 'pet_profile_snapshot')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('pet_profile_snapshot'));
            }
        }
    }
};
