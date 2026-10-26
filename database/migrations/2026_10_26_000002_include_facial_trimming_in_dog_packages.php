<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $facial = DB::table('services')->where('slug', 'facial_trimming')->value('service_id');
            if (! $facial) return;
            $packages = DB::table('services')->where('kind', 'package')->where('pet_type', 'dog')
                ->whereIn('slug', ['partial_grooming', 'regular_dog_grooming', 'deluxe_dog_grooming'])
                ->lockForUpdate()->get();
            foreach ($packages as $package) {
                $included = json_decode($package->included_ala_carte_ids ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                if (! in_array((int) $facial, array_map('intval', $included), true)) {
                    $included[] = (int) $facial;
                    DB::table('services')->where('service_id', $package->service_id)
                        ->update(['included_ala_carte_ids' => json_encode($included, JSON_THROW_ON_ERROR)]);
                }
            }
        });
    }

    public function down(): void
    {
        // Do not remove managed inclusions or rewrite historical billing.
    }
};
