<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_services', function (Blueprint $table) {
            $table->string('service_name_snapshot', 150)->nullable();
            $table->string('service_kind_snapshot', 20)->nullable();
        });
        // Legacy records have no original-name audit. Preserve the available names
        // now, including inactive catalogue entries, before subsequent edits.
        foreach (DB::table('services')->get(['service_id', 'service_name', 'kind', 'slug']) as $service) {
            DB::table('booking_services')->where('service_id', $service->service_id)->whereNull('addon_id')->update([
                'service_name_snapshot' => $service->service_name,
                'service_kind_snapshot' => $service->kind ?? config("grooming_services.services.{$service->slug}.kind"),
            ]);
        }
        foreach (DB::table('addons')->get(['addon_id', 'addon_name']) as $addon) {
            DB::table('booking_services')->where('addon_id', $addon->addon_id)->update([
                'service_name_snapshot' => $addon->addon_name, 'service_kind_snapshot' => 'addon',
            ]);
        }
        // Early checkouts can have a null readiness total. Their frozen product
        // amounts still identify the exact grooming portion even on stock conflict.
        if (Schema::hasTable('gateway_payments')) {
            DB::table('gateway_payments')->where('context', 'grooming')->orderBy('id')->chunkById(200, function ($gateways) {
                foreach ($gateways as $gateway) {
                    $snapshot = json_decode($gateway->snapshot ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                    if (isset($snapshot['grooming_total']) || ! isset($snapshot['total'])) continue;
                    $groomingCents = (int) round((float) $snapshot['total'] * 100)
                        - array_sum(array_column($snapshot['products'] ?? [], 'subtotalCents'));
                    $snapshot['grooming_total'] = number_format($groomingCents / 100, 2, '.', '');
                    DB::table('gateway_payments')->where('id', $gateway->id)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('booking_services', fn (Blueprint $table) => $table->dropColumn(['service_name_snapshot', 'service_kind_snapshot']));
    }
};
