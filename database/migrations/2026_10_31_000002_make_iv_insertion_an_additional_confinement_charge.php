<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Names identify this one-time data correction, never runtime billing policy.
            $matches = DB::table('clinic_services')->whereIn('category_id',
                DB::table('clinic_service_categories')->select('id')->where('name', 'Confinement'))
                ->lockForUpdate()->get()->filter(fn ($service) =>
                    mb_strtolower(preg_replace('/\s+/u', ' ', trim($service->name))) === 'iv insertion');

            if ($matches->isEmpty()) {
                Log::warning('Confinement IV Insertion policy not applied: no matching catalogue record. Review Clinic Services before admission.');
                return;
            }
            if ($matches->count() !== 1) {
                throw new RuntimeException('Ambiguous Confinement IV Insertion records. Review Clinic Services before rerunning this migration.');
            }
            $service = $matches->first();
            if (! in_array($service->charge_behavior, ['admission_charge', 'additional_charge'], true)
                || ! in_array($service->billing_unit, [null, 'service'], true)) {
                throw new RuntimeException('Unexpected Confinement IV Insertion configuration. Review Clinic Services before rerunning this migration.');
            }

            // Preserve prices, activation, timestamps and every historical charge/snapshot.
            DB::table('clinic_services')->where('id', $service->id)->update([
                'charge_behavior' => 'additional_charge', 'billing_unit' => 'service',
            ]);
        });
    }

    public function down(): void
    {
        // Keep the manual-charge policy on rollback; recorded charges are never rewritten.
    }
};
