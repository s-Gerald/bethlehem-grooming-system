<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->json('time_estimates')->nullable();
            $table->json('haircut_options')->nullable();
            $table->json('legacy_complete_estimates')->nullable();
        });
        Schema::table('booking_pets', fn (Blueprint $table) => $table->string('grooming_preference_name', 100)->nullable());

        // Migration-only snapshots. Never split a historical complete time arbitrarily.
        $times = require database_path('data/grooming-legacy-estimates.php');
        $preferences = require database_path('data/grooming-legacy-haircuts.php');
        foreach (DB::table('services')->get() as $service) {
            $rules = $times['packages'][$service->slug] ?? null;
            $sizes = $rules['sizes'] ?? [];
            if (isset($times['ala_carte'][$service->slug])) $sizes = array_fill_keys(['small','medium','large','extra_large'], $times['ala_carte'][$service->slug]);
            $haircuts = [];
            foreach ($preferences[$service->slug] ?? [] as $id => $name) {
                $haircuts[] = ['id'=>$id, 'name'=>$name, 'isActive'=>true, 'estimates'=>[]];
            }
            DB::table('services')->where('service_id', $service->service_id)->update([
                'time_estimates'=>json_encode($sizes), 'haircut_options'=>json_encode($haircuts),
                'legacy_complete_estimates'=>isset($rules['cuts']) ? json_encode($rules['cuts']) : null,
            ]);
        }
        foreach ($preferences as $choices) foreach ($choices as $id => $name) {
            DB::table('booking_pets')->where('grooming_preference', $id)->update(['grooming_preference_name'=>$name]);
        }
    }

    public function down(): void
    {
        // Forward-only: rolling back would discard managed settings and historical names.
        throw new \RuntimeException('Grooming time configuration contains persistent data; use a forward migration.');
    }
};
