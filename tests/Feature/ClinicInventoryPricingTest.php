<?php

namespace Tests\Feature;

use App\Models\ClinicAppointment;
use App\Models\InventoryItem;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicInventoryPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id'); $table->string('role');
            $table->string('first_name')->nullable(); $table->string('last_name')->nullable();
            $table->string('staff_subrole')->nullable(); $table->boolean('is_active')->default(true);
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id'); $table->string('pet_name'); $table->decimal('weight', 8, 2)->nullable();
        });
        Schema::create('walkins', fn (Blueprint $table) => $table->id());
        foreach (['2026_07_05_100000_create_inventory_items_table', '2026_07_04_211409_create_clinic_appointments_table',
            '2026_07_04_211409_create_clinic_records_table', '2026_07_04_211409_create_clinic_vitals_table',
            '2026_07_04_211410_create_clinic_attachments_table', '2026_10_10_000001_create_clinic_charges_table',
            '2026_10_10_000002_add_clinic_inventory_pricing', '2026_10_11_000001_add_inventory_weight_pricing_mode',
            '2026_10_12_000002_extend_clinic_consultation_records'] as $file) {
            (require database_path("migrations/{$file}.php"))->up();
        }
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->increments('transaction_id'); $table->unsignedInteger('item_id'); $table->string('type');
            $table->decimal('quantity', 10, 2); $table->decimal('unit_cost_at_time', 8, 2)->nullable();
            $table->decimal('selling_price_at_time', 8, 2)->nullable(); $table->string('reason');
            $table->string('batch_number')->nullable(); $table->date('expiry_date')->nullable();
            $table->string('reference_type')->default('manual'); $table->unsignedInteger('reference_id')->nullable();
            $table->text('notes')->nullable(); $table->unsignedInteger('performed_by')->nullable(); $table->timestamp('created_at')->nullable();
        });
        (require database_path('migrations/2026_10_23_000001_add_inventory_receipt_references.php'))->up();
        Sanctum::actingAs(User::create(['role' => 'admin']));
    }

    // Exercise the existing Treatment charge service without using Medical Record as a billing source.
    private function saveMedicineCharges(ClinicAppointment $appointment, array $entries): \Illuminate\Testing\TestResponse
    {
        try {
            DB::transaction(function () use ($appointment, $entries) {
                $case = ClinicAppointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
                app(\App\Services\ClinicMedicineChargeService::class)->save($case, $entries, auth()->user());
            });
            return \Illuminate\Testing\TestResponse::fromBaseResponse(response()->json([], 200));
        } catch (\Illuminate\Validation\ValidationException $error) {
            return \Illuminate\Testing\TestResponse::fromBaseResponse(response()->json(['errors' => $error->errors()], 422));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            return \Illuminate\Testing\TestResponse::fromBaseResponse(response()->json(['message' => $error->getMessage()], $error->getStatusCode()));
        }
    }

    public function test_medical_record_rejects_medicine_charge_payloads_and_preserves_clinical_content(): void
    {
        $item = $this->item(); $appt = $this->appointment();
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['diagnosis' => 'Vet-entered diagnosis'])->assertOk();
        $record = DB::table('clinic_records')->first();
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', [
            'diagnosis' => 'Must not overwrite', 'medicines' => [$this->charge($item)],
        ])->assertUnprocessable()->assertJsonValidationErrors('medicines');
        $this->assertEquals($record, DB::table('clinic_records')->first());
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_product_units_are_physical_and_legacy_measured_units_remain_readable_and_unchanged(): void
    {
        foreach (['vial', 'bottle', 'tablet', 'ampoule', 'syringe', 'tube', 'bag', 'dose kit', 'custom carton'] as $unit) {
            $this->item(['unit' => $unit]);
        }
        foreach (['kg', 'g', 'L', 'mL'] as $unit) {
            $this->postJson('/api/inventory/items', $this->product(['unit' => $unit]))
                ->assertUnprocessable()->assertJsonValidationErrors('unit');
            $item = $this->item();
            // Simulate existing data without converting or rounding its quantity.
            DB::table('inventory_items')->where('item_id', $item->item_id)
                ->update(['unit' => $unit, 'quantity_on_hand' => 3.5]);
            $this->getJson('/api/inventory/items/'.$item->item_id)->assertOk()
                ->assertJsonPath('data.unit', $unit)->assertJsonPath('data.quantity_on_hand', '3.50');
            $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['unit' => $unit]))
                ->assertOk()->assertJsonPath('data.unit', $unit)->assertJsonPath('data.quantity_on_hand', '3.50');
        }
    }

    private function product(array $changes = []): array
    {
        $payload = array_replace([
            'item_name' => 'Clinic medicine', 'category' => 'medicine', 'unit' => 'bottle',
            'medicine_type' => 'deworming', 'pricing_method' => 'weight_based',
            'weight_pricing_mode' => 'ranges', 'strength_concentration' => '10 mg/mL', 'unit_cost' => 50, 'selling_price' => null, 'reorder_level' => 0,
            'weight_prices' => [
                ['min_weight_kg' => 1, 'max_weight_kg' => 5, 'price' => 200],
                ['min_weight_kg' => 5.1, 'max_weight_kg' => 10, 'price' => 400],
                ['min_weight_kg' => 10.1, 'max_weight_kg' => 15, 'price' => 700],
                ['min_weight_kg' => 15.1, 'max_weight_kg' => 50, 'price' => 900],
            ],
        ], $changes);
        if (($payload['weight_pricing_mode'] ?? null) === 'threshold') {
            $payload['weight_final_range'] = $changes['weight_final_range'] ?? (($payload['weight_prices'][array_key_last($payload['weight_prices'])]['max_weight_kg'] ?? null) === null ? 'open' : 'capped');
        }
        if (($payload['weight_pricing_mode'] ?? null) === 'catalog') {
            $payload['weight_prices'] = array_map(fn ($range) => [...$range,
                'band' => $range['band'] ?? ((float) $range['min_weight_kg'] === 0.0 ? 'below' : ($range['max_weight_kg'] === null ? 'above' : 'normal')),
            ], $payload['weight_prices']);
        }
        return $payload;
    }

    private function item(array $changes = []): InventoryItem
    {
        $id = $this->postJson('/api/inventory/items', $this->product($changes))->assertCreated()->json('data.item_id');
        return InventoryItem::findOrFail($id);
    }

    private function appointment(?float $weight = 12.4): ClinicAppointment
    {
        $pet = Pet::create(['pet_name' => 'Patient', 'weight' => $weight]);
        $case = ClinicAppointment::create(['appointment_reference' => 'TEST-'.Str::random(8),
            'appointment_date' => today(), 'pet_id' => $pet->pet_id, 'status' => 'in_consultation']);
        $case->vitals()->create(['weight_kg' => $weight]);
        return $case;
    }

    private function charge(InventoryItem $item, float $price = 700, ?float $weight = 12.4, ?float $quantity = null): array
    {
        return ['inventory_item_id' => $item->item_id, 'expected_price' => $price,
            'expected_weight_kg' => $weight, 'inventory_quantity' => $quantity, 'request_key' => (string) Str::uuid()];
    }

    public function test_catalog_examples_preserve_display_endpoints_and_decimal_prices_on_add_and_edit(): void
    {
        $examples = json_decode(file_get_contents(base_path('tests/Fixtures/weight-rules.json')), true)['catalog_pricing'];
        foreach ($examples as $example) {
            $ranges = array_map(fn ($r) => ['min_weight_kg' => $r[0], 'max_weight_kg' => $r[1], 'price' => $r[2]], $example['ranges']);
            $item = $this->item(['weight_pricing_mode' => 'catalog', 'weight_prices' => $ranges, 'unit_cost' => 73.25]);
            $this->assertSame('catalog', $item->weight_pricing_mode);
            foreach ($example['examples'] as [$weight, $price]) {
                $range = \App\Support\ProductWeightPricing::rangeFor($item->weightPrices->toArray(), $weight, 'catalog');
                $this->assertEquals($price, $range['price'] ?? null);
                if ($price !== null) $this->assertEquals($price, $item->clinicPrice($weight)['price']);
            }
            $this->postJson('/api/inventory/stock-in', ['items' => [[
                'item_id' => $item->item_id, 'quantity' => 3, 'reason' => 'purchase',
                'batch_number' => 'CATALOG', 'expiry_date' => today()->addYear()->toDateString(),
            ]]])->assertCreated();
            $this->putJson('/api/inventory/items/'.$item->item_id, $this->product([
                'weight_pricing_mode' => 'catalog', 'weight_prices' => $ranges, 'unit_cost' => 73.25,
            ]))->assertOk();
            $details = $this->getJson('/api/inventory/items/'.$item->item_id)->assertOk();
            foreach ($ranges as $index => $range) {
                $details->assertJsonPath('data.weight_prices.'.$index.'.min_weight_kg', number_format($range['min_weight_kg'], 2, '.', ''))
                    ->assertJsonPath('data.weight_prices.'.$index.'.max_weight_kg', $range['max_weight_kg'] === null ? null : number_format($range['max_weight_kg'], 2, '.', ''));
            }
            $this->putJson('/api/inventory/items/'.$item->item_id, [
                'item_name' => 'Updated catalog', 'unit_cost' => 73.25, 'reorder_level' => 0,
            ])->assertOk()->assertJsonPath('data.weight_pricing_mode', 'catalog');
            $this->assertSame('73.25', $item->fresh()->unit_cost);
            $this->assertSame('3.00', $item->fresh()->quantity_on_hand);
            $this->assertNull($item->fresh()->selling_price);
        }
    }

    public function test_invalid_catalog_rows_are_rejected_on_add_and_edit_without_mutating_saved_prices(): void
    {
        $r = fn ($min, $max, $price = 200, $band = 'normal') => ['band' => $band, 'min_weight_kg' => $min, 'max_weight_kg' => $max, 'price' => $price];
        $valid = [$r(0, 1, 200, 'below'), $r(1, 4, 250), $r(5, 10, 300), $r(10, null, 400, 'above')];
        $item = $this->item(['weight_pricing_mode' => 'catalog', 'weight_prices' => $valid]);
        foreach ([[$r(1, null)], [$r(1, '')], [$r(0, 4)], [$r(1, 1)], [$r(5, 4)],
            [$r(1, 6), $r(5, 10)], [$r(1, 4), $r(1, 5)], [$r(5, 10), $r(1, 4)],
            [$r(0, 6, 200, 'below'), $r(5, 10)], [$r(0, 1, 200, 'below'), $r(0, 2, 200, 'below')],
            [$r(1, 4), $r(0, 1, 200, 'below')], [$r(1, 4, 200, 'below')],
            [$r(1, null, 200, 'above'), $r(5, 10)], [$r(1, 10), $r(9, null, 200, 'above')],
            [$r(1, 10, 200, 'above')], [$r(10, null, 200, 'above'), $r(20, null, 200, 'above')],
            [$r(0, null, 200, 'above')], [$r(-1, 4)], [$r('1.000', 4)], [$r(1, '4.200000')],
            [$r(1, 4, 0)], [$r(1, 4, '2.345')], [$r(0, '1.000', 200, 'below')], [$r('40.000', null, 200, 'above')]] as $bad) {
            $payload = $this->product(['weight_pricing_mode' => 'catalog', 'weight_prices' => $bad]);
            $this->postJson('/api/inventory/items', $payload)->assertUnprocessable();
            $this->putJson('/api/inventory/items/'.$item->item_id, $payload)->assertUnprocessable();
        }
        $missing = $this->product(['weight_pricing_mode' => 'catalog', 'weight_prices' => $valid]);
        unset($missing['weight_prices'][1]['max_weight_kg']);
        $this->postJson('/api/inventory/items', $missing)->assertUnprocessable()->assertJsonValidationErrors('weight_prices.1.max_weight_kg');
        $missing = $this->product(['weight_pricing_mode' => 'catalog', 'weight_prices' => $valid]);
        unset($missing['weight_prices'][3]['band']);
        $this->postJson('/api/inventory/items', $missing)->assertUnprocessable()->assertJsonValidationErrors('weight_prices.3.band');
        $this->assertSame('4.00', $item->fresh()->weightPrices[1]->max_weight_kg);
        $this->assertEquals(250, $item->fresh()->clinicPrice(4.99)['price']);
        $this->assertEquals(300, $item->fresh()->clinicPrice(10)['price']);
        $this->assertEquals(400, $item->fresh()->clinicPrice(10.01)['price']);
    }

    public function test_catalog_normal_ranges_require_a_tenth_kg_gap_on_add_and_edit(): void
    {
        foreach ([[5, 5.1, 6, '5', '5.1'], [5.99, 6.09, 7, '5.99', '6.09']] as [$maximum, $minimum, $end, $displayMaximum, $displayMinimum]) {
            $ranges = [
                ['min_weight_kg' => 1, 'max_weight_kg' => $maximum, 'price' => 1750],
                ['min_weight_kg' => $minimum, 'max_weight_kg' => $end, 'price' => 1900],
            ];
            $item = $this->item(['weight_pricing_mode' => 'catalog', 'weight_prices' => $ranges]);
            $message = 'Must be greater than '.$displayMaximum.' kg. Minimum allowed: '.$displayMinimum.' kg.';
            foreach ([$maximum, round($maximum + 0.01, 2), round($maximum + 0.09, 2)] as $invalidMinimum) {
                $invalid = $ranges;
                $invalid[1]['min_weight_kg'] = $invalidMinimum;
                $payload = $this->product(['weight_pricing_mode' => 'catalog', 'weight_prices' => $invalid]);
                $this->postJson('/api/inventory/items', $payload)->assertUnprocessable()
                    ->assertJsonValidationErrors('weight_prices')->assertJsonPath('errors.weight_prices.0', $message);
                $this->putJson('/api/inventory/items/'.$item->item_id, $payload)->assertUnprocessable()
                    ->assertJsonValidationErrors('weight_prices')->assertJsonPath('errors.weight_prices.0', $message);
            }
            $this->assertSame(number_format($minimum, 2, '.', ''), $item->fresh()->weightPrices[1]->min_weight_kg);
            foreach ([$maximum, round($maximum + 0.01, 2), round($maximum + 0.09, 2)] as $weight) {
                $this->assertEquals(1750, $item->fresh()->clinicPrice($weight)['price']);
            }
            $this->assertEquals(1900, $item->fresh()->clinicPrice($minimum)['price']);
            $this->putJson('/api/inventory/items/'.$item->item_id,
                $this->product(['weight_pricing_mode' => 'catalog', 'weight_prices' => $ranges]))->assertOk();
        }
    }

    public function test_catalog_above_boundary_and_final_cap_are_used_by_medicine_charges(): void
    {
        $item = $this->item(['weight_pricing_mode' => 'catalog', 'weight_prices' => [
            ['min_weight_kg' => 31, 'max_weight_kg' => 40, 'price' => 650],
            ['min_weight_kg' => 40, 'max_weight_kg' => null, 'price' => 800],
        ]]);
        foreach ([[40, 650], [40.01, 800], [41, 800]] as [$weight, $price]) {
            $appointment = $this->appointment($weight);
            $this->saveMedicineCharges($appointment, [$this->charge($item, $price, $weight)])->assertOk();
        }
        $this->putJson('/api/inventory/items/'.$item->item_id, $this->product([
            'weight_pricing_mode' => 'catalog', 'weight_prices' => [['min_weight_kg' => 31, 'max_weight_kg' => 40, 'price' => 650]],
        ]))->assertOk();
        $appointment = $this->appointment(40.01);
        $this->saveMedicineCharges($appointment, [$this->charge($item, 650, 40.01)])->assertUnprocessable();
        $this->assertDatabaseCount('clinic_charges', 3);
    }

    public function test_threshold_examples_round_trip_on_add_and_edit_without_changing_cost_or_stock(): void
    {
        $examples = json_decode(file_get_contents(base_path('tests/Fixtures/weight-rules.json')), true)['pricing'];
        foreach ($examples as $example) {
            $ranges = array_map(fn ($tier) => ['min_weight_kg' => $tier[0], 'max_weight_kg' => $tier[1], 'price' => $tier[2]], $example['ranges']);
            $item = $this->item(['weight_prices' => $ranges, 'weight_pricing_mode' => 'threshold', 'unit_cost' => 73.25]);
            $this->assertSame('threshold', $item->weight_pricing_mode);
            $this->assertSame('73.25', $item->unit_cost);
            $this->assertNull($item->selling_price);
            foreach ($example['examples'] as [$weight, $price]) {
                $range = \App\Support\ProductWeightPricing::rangeFor($item->weightPrices->toArray(), $weight);
                $this->assertEquals($price, $range['price'] ?? null);
                if ($price !== null) $this->assertEquals($price, $item->clinicPrice($weight)['price']);
            }
            $this->postJson('/api/inventory/stock-in', ['items' => [[
                'item_id' => $item->item_id, 'quantity' => 3, 'reason' => 'purchase',
                'batch_number' => 'TIERS', 'expiry_date' => today()->addYear()->toDateString(),
            ]]])->assertCreated();
            $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['weight_prices' => $ranges, 'weight_pricing_mode' => 'threshold', 'unit_cost' => 73.25]))
                ->assertOk()->assertJsonPath('data.weight_pricing_mode', 'threshold')->assertJsonPath('data.unit_cost', '73.25');
            $this->assertSame('3.00', $item->fresh()->quantity_on_hand);
            $bad = $ranges;
            if (count($bad) > 1) $bad[1]['min_weight_kg'] = $bad[0]['min_weight_kg'];
            else $bad[0]['max_weight_kg'] = null;
            $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['weight_prices' => $bad, 'weight_pricing_mode' => 'threshold']))->assertUnprocessable();
            $this->assertSame('73.25', $item->fresh()->unit_cost);
        }
    }

    public function test_editing_an_explicit_schedule_uses_saved_starts_and_preserves_its_final_cap(): void
    {
        $item = $this->item(['unit_cost' => 73.25, 'weight_prices' => [
            ['min_weight_kg' => 1, 'max_weight_kg' => 4, 'price' => 250],
            ['min_weight_kg' => 5, 'max_weight_kg' => 10, 'price' => 300],
        ]]);
        $this->assertNull(\App\Support\ProductWeightPricing::rangeFor($item->weightPrices->toArray(), 4.1, 'ranges'));
        $ranges = [['min_weight_kg' => '1', 'max_weight_kg' => '5', 'price' => '250'],
            ['min_weight_kg' => '5', 'max_weight_kg' => '10', 'price' => '300']];
        $this->putJson('/api/inventory/items/'.$item->item_id, $this->product([
            'weight_pricing_mode' => 'threshold', 'weight_prices' => $ranges, 'unit_cost' => 73.25,
        ]))->assertOk()->assertJsonPath('data.weight_pricing_mode', 'threshold');
        $this->assertEquals(250, $item->fresh()->clinicPrice(4.99)['price']);
        $this->assertEquals(300, $item->fresh()->clinicPrice(10)['price']);
        $this->assertNull(\App\Support\ProductWeightPricing::rangeFor($item->fresh()->weightPrices->toArray(), 10.01));
        $this->assertSame('73.25', $item->fresh()->unit_cost);
        $this->assertSame('0.00', $item->fresh()->quantity_on_hand);
        $this->getJson('/api/inventory/items/'.$item->item_id)->assertOk()
            ->assertJsonPath('data.weight_prices.0.max_weight_kg', '5.00')
            ->assertJsonPath('data.weight_prices.1.max_weight_kg', '10.00');
    }

    public function test_invalid_threshold_configurations_are_rejected_on_add_and_edit(): void
    {
        $r = fn ($min, $max, $price = 200) => ['min_weight_kg' => $min, 'max_weight_kg' => $max, 'price' => $price];
        $valid = [$r(0, 1), $r(1, 5, 250), $r(5, null, 300)];
        $item = $this->item(['weight_pricing_mode' => 'threshold', 'weight_prices' => $valid]);
        foreach ([[$r(0, null)], [$r(0, 1), $r(0, null)], [$r(1, 5), $r(1, null)],
            [$r(5, 1), $r(1, null)], [$r(1, 4), $r(5, null)], [$r(1, 6), $r(5, null)],
            [$r(1, null), $r(5, null)], [$r(1, 1)], [$r(-1, 5)], [$r('1.000', 5)],
            [$r(1, '5.000')], [$r(0, '1.000')], [$r(1, 5, 0)], [$r(1, 5, '2.345')]] as $bad) {
            $payload = $this->product(['weight_pricing_mode' => 'threshold', 'weight_prices' => $bad]);
            $this->postJson('/api/inventory/items', $payload)->assertUnprocessable();
            $this->putJson('/api/inventory/items/'.$item->item_id, $payload)->assertUnprocessable();
        }
        $missing = $valid; unset($missing[2]['max_weight_kg']);
        $this->postJson('/api/inventory/items', $this->product(['weight_pricing_mode' => 'threshold', 'weight_prices' => $missing]))
            ->assertUnprocessable()->assertJsonValidationErrors('weight_prices.2.max_weight_kg');
        foreach (['', null] as $missingCap) {
            $bad = $valid; $bad[2]['max_weight_kg'] = $missingCap;
            $this->postJson('/api/inventory/items', $this->product(['weight_pricing_mode' => 'threshold', 'weight_final_range' => 'capped', 'weight_prices' => $bad]))
                ->assertUnprocessable()->assertJsonValidationErrors('weight_prices');
        }
        $implicitOpen = $this->product(['weight_pricing_mode' => 'threshold', 'weight_prices' => $valid]);
        unset($implicitOpen['weight_final_range']);
        $this->postJson('/api/inventory/items', $implicitOpen)->assertUnprocessable()->assertJsonValidationErrors('weight_prices');
        $this->assertSame('threshold', $item->fresh()->weight_pricing_mode);
        $this->assertSame(3, $item->weightPrices()->count());
        $this->assertEquals(250, $item->fresh()->clinicPrice(4.99)['price']);
    }

    public function test_clinic_charges_use_exact_decimal_thresholds_and_honor_the_final_cap(): void
    {
        $ranges = [['min_weight_kg' => 0, 'max_weight_kg' => 1, 'price' => 200],
            ['min_weight_kg' => 1, 'max_weight_kg' => 5, 'price' => 250],
            ['min_weight_kg' => 5, 'max_weight_kg' => 11, 'price' => 300],
            ['min_weight_kg' => 11, 'max_weight_kg' => 41, 'price' => 350],
            ['min_weight_kg' => 41, 'max_weight_kg' => null, 'price' => 750]];
        $item = $this->item(['weight_pricing_mode' => 'threshold', 'weight_prices' => $ranges]);
        foreach ([[0.5, 200], [4.10, 250], [4.99, 250], [5, 300], [10.50, 300], [10.99, 300], [11, 350], [52.35, 750]] as [$weight, $price]) {
            $appt = $this->appointment($weight);
            $this->saveMedicineCharges($appt, [$this->charge($item, $price, $weight)])
                ->assertOk();
            $this->assertDatabaseHas('clinic_charges', ['clinic_appointment_id' => $appt->id, 'amount' => $price, 'pet_weight_kg' => $weight]);
        }
        $ranges[4]['max_weight_kg'] = 50;
        $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['weight_pricing_mode' => 'threshold', 'weight_prices' => $ranges]))->assertOk();
        $appt = $this->appointment(50.01);
        $this->saveMedicineCharges($appt, [$this->charge($item, 750, 50.01)])
            ->assertUnprocessable()->assertJsonValidationErrors('medicine');
        $this->assertSame(0, $appt->charges()->count());
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_decimal_endpoints_and_invalid_order_are_validated_by_the_server(): void
    {
        $ranges = [['min_weight_kg' => '0.50', 'max_weight_kg' => '2.25', 'price' => 100],
            ['min_weight_kg' => '2.26', 'max_weight_kg' => '4.50', 'price' => 200],
            ['min_weight_kg' => '4.51', 'max_weight_kg' => '10.', 'price' => 300]];
        $item = $this->item(['weight_prices' => $ranges]);
        foreach ([[2.25, 100], [2.26, 200], [4.5, 200], [4.51, 300]] as [$weight, $price]) {
            $this->assertEquals($price, $item->clinicPrice($weight)['price']);
        }
        $descending = [$ranges[0], $ranges[2], $ranges[1]];
        $this->postJson('/api/inventory/items', $this->product(['weight_prices' => $descending]))->assertUnprocessable();
        foreach (['2.250', '2.200000', '10.555', '1e1'] as $invalid) {
            foreach (['min_weight_kg', 'max_weight_kg'] as $field) {
                $bad = $ranges; $bad[0][$field] = $invalid;
                $this->postJson('/api/inventory/items', $this->product(['weight_prices' => $bad]))
                    ->assertUnprocessable()->assertJsonValidationErrors('weight_prices.0.'.$field);
                $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['weight_prices' => $bad]))
                    ->assertUnprocessable()->assertJsonValidationErrors('weight_prices.0.'.$field);
            }
        }
        $this->assertSame('0.50', $item->fresh()->weightPrices[0]->min_weight_kg);
    }

    public function test_saved_legacy_ranges_survive_unrelated_edits_and_require_explicit_endpoints_when_edited(): void
    {
        $item = $this->item();
        $legacy = [['min_weight_kg' => 0, 'max_weight_kg' => 2, 'price' => 250],
            ['min_weight_kg' => 2.1, 'max_weight_kg' => null, 'price' => 350]];
        $item->weightPrices()->delete(); $item->weightPrices()->createMany($legacy);
        $item->update(['weight_pricing_mode' => 'legacy', 'quantity_on_hand' => 7]);
        $this->assertEquals(250, $item->fresh()->clinicPrice(2)['price']);
        $this->putJson('/api/inventory/items/'.$item->item_id, ['description' => 'Updated', 'unit_cost' => 50, 'reorder_level' => 0])
            ->assertOk()->assertJsonPath('data.weight_pricing_mode', 'legacy')->assertJsonPath('data.weight_prices.1.max_weight_kg', null);
        $this->assertSame('7.00', $item->fresh()->quantity_on_hand);
        $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['weight_prices' => $legacy]))->assertUnprocessable();
        $refined = [['min_weight_kg' => 1, 'max_weight_kg' => 2, 'price' => 250],
            ['min_weight_kg' => 2.1, 'max_weight_kg' => 4, 'price' => 350]];
        $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['weight_prices' => $refined]))
            ->assertOk()->assertJsonPath('data.weight_pricing_mode', 'ranges');
        $this->assertEquals(350, $item->fresh()->clinicPrice(2.1)['price']);
        $this->assertSame('7.00', $item->fresh()->quantity_on_hand);
    }

    public function test_historical_missing_maximum_never_means_unlimited(): void
    {
        $item = $this->item();
        $item->weightPrices()->where('min_weight_kg', 15.1)->update(['max_weight_kg' => null]);
        foreach (['legacy', 'ranges'] as $mode) {
            $item->update(['weight_pricing_mode' => $mode]);
            $appt = $this->appointment(41);
            $this->saveMedicineCharges($appt, [$this->charge($item, 900, 41)])
                ->assertUnprocessable()->assertJsonValidationErrors('medicine');
            $this->assertSame(0, $appt->charges()->count());
        }
    }

    public function test_medicine_types_and_ordered_relational_ranges_round_trip(): void
    {
        foreach (InventoryItem::MEDICINE_TYPES as $type) {
            $item = $this->item(['medicine_type' => $type]);
            $this->assertNull($item->selling_price);
            $this->assertSame($type, $item->medicine_type);
            $this->assertEquals([1, 5.1, 10.1, 15.1], $item->weightPrices->pluck('min_weight_kg')->map(fn ($n) => (float) $n)->all());
            $this->getJson('/api/inventory/items/'.$item->item_id)->assertOk()->assertJsonCount(4, 'data.weight_prices');
            $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['medicine_type' => $type]))->assertOk();
            $this->assertSame(4, $item->weightPrices()->count());
        }
    }

    public function test_required_fields_and_all_invalid_range_configurations_are_rejected(): void
    {
        foreach (['medicine_type', 'unit', 'pricing_method'] as $field) {
            $payload = $this->product(); unset($payload[$field]);
            $this->postJson('/api/inventory/items', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $r = fn ($min, $max, $price = 200) => ['min_weight_kg' => $min, 'max_weight_kg' => $max, 'price' => $price];
        foreach ([[], [$r(-1, 5)], [$r(5, 5)], [$r(5, 4)], [$r(1, 5, 0)], [$r(1, 5, -1)],
            [$r(1, 5, 'NaN')], [$r(1, 5, 2.345)], [$r(0, 5), $r(4, 10)], [$r(0, 5), $r(0, 5)],
            [$r(0, null), $r(5, 10)], [$r(0.001, 5)], [$r(1, null)], [$r(1, 5), $r(5, 10)], [$r(1, 5), $r(1, 5)], [$r(6, 10), $r(1, 5)]] as $ranges) {
            $this->postJson('/api/inventory/items', $this->product(['weight_prices' => $ranges]))->assertUnprocessable();
        }
        $this->postJson('/api/inventory/items', $this->product(['selling_price' => 100]))->assertUnprocessable()->assertJsonValidationErrors('selling_price');
        $this->assertDatabaseCount('inventory_items', 0);
        $this->assertDatabaseCount('inventory_item_weight_prices', 0);
    }

    public function test_invalid_schedule_edits_preserve_saved_prices_cost_and_stock(): void
    {
        $item = $this->item(['unit_cost' => 73.25]);
        $this->postJson('/api/inventory/stock-in', ['items' => [[
            'item_id' => $item->item_id, 'quantity' => 3, 'reason' => 'purchase',
            'batch_number' => 'RANGES', 'expiry_date' => today()->addYear()->toDateString(),
        ]]])->assertCreated();
        $saved = $item->fresh()->weightPrices->map->only(['min_weight_kg', 'max_weight_kg', 'price'])->all();
        foreach ([5, 4, 1] as $overlap) {
            $bad = $this->product()['weight_prices']; $bad[1]['min_weight_kg'] = $overlap;
            $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['weight_prices' => $bad]))
                ->assertUnprocessable()->assertJsonValidationErrors('weight_prices');
        }
        $bad = $this->product()['weight_prices']; $bad[3]['max_weight_kg'] = null;
        $this->putJson('/api/inventory/items/'.$item->item_id, $this->product(['weight_prices' => $bad]))
            ->assertUnprocessable()->assertJsonValidationErrors('weight_prices.3.max_weight_kg');
        $this->assertSame($saved, $item->fresh()->weightPrices->map->only(['min_weight_kg', 'max_weight_kg', 'price'])->all());
        $this->assertSame('73.25', $item->fresh()->unit_cost);
        $this->assertSame('3.00', $item->fresh()->quantity_on_hand);
    }

    public function test_products_can_be_created_and_edited_with_pipette_without_a_dose_unit(): void
    {
        foreach (['medicine', 'vaccine'] as $category) {
            $payload = $this->product(['category' => $category, 'unit' => 'pipette',
                'medicine_type' => $category === 'medicine' ? 'general_medication' : null,
                'pricing_method' => 'flat', 'weight_prices' => [], 'selling_price' => 75]);
            unset($payload['dose_unit']);
            $id = $this->postJson('/api/inventory/items', $payload)->assertCreated()
                ->assertJsonPath('data.unit', 'pipette')->assertJsonMissingPath('data.dose_unit')->json('data.item_id');
            $this->putJson('/api/inventory/items/'.$id, [...$payload, 'selling_price' => 80])
                ->assertOk()->assertJsonPath('data.unit', 'pipette')->assertJsonPath('data.selling_price', '80.00');
            $missingUnit = $payload; unset($missingUnit['unit']);
            $this->postJson('/api/inventory/items', $missingUnit)->assertUnprocessable()->assertJsonValidationErrors('unit');
        }
        $payload = $this->product(); unset($payload['dose_unit']);
        $id = $this->postJson('/api/inventory/items', $payload)->assertCreated()
            ->assertJsonMissingPath('data.dose_unit')->assertJsonCount(4, 'data.weight_prices')->json('data.item_id');
        $this->putJson('/api/inventory/items/'.$id, $payload)->assertOk()->assertJsonCount(4, 'data.weight_prices');
    }

    public function test_inventory_product_edits_do_not_expose_a_clinical_dose_unit(): void
    {
        $item = $this->item();
        $this->putJson('/api/inventory/items/'.$item->item_id, $this->product())
            ->assertOk()->assertJsonMissingPath('data.dose_unit');
    }

    public function test_vaccines_flat_medicines_and_retail_keep_flat_prices(): void
    {
        $vaccine = $this->product(['category' => 'vaccine', 'medicine_type' => null, 'pricing_method' => 'flat', 'weight_prices' => [], 'selling_price' => 950]);
        $id = $this->postJson('/api/inventory/items', $vaccine)->assertCreated()->assertJsonPath('data.pricing_method', 'flat')->json('data.item_id');
        $this->putJson('/api/inventory/items/'.$id, [...$vaccine, 'selling_price' => 975])->assertOk()->assertJsonPath('data.selling_price', '975.00');
        $this->postJson('/api/inventory/items', [...$vaccine, ])->assertCreated()->assertJsonMissingPath('data.dose_unit');
        $this->postJson('/api/inventory/items', [...$vaccine, 'medicine_type' => 'deworming'])->assertUnprocessable();
        $this->postJson('/api/inventory/items', [...$vaccine, 'pricing_method' => 'weight_based'])->assertUnprocessable();
        $this->postJson('/api/inventory/items', [...$vaccine, 'weight_prices' => $this->product()['weight_prices']])->assertUnprocessable();
        $flat = $this->item(['pricing_method' => 'flat', 'weight_prices' => [], 'selling_price' => 300]);
        $this->assertSame('300.00', $flat->clinicPrice(null)['price']);
        foreach (InventoryItem::RETAIL_CATEGORIES as $category) {
            $payload = ['item_name' => $category, 'category' => $category, 'unit' => 'box', 'unit_cost' => 20, 'selling_price' => 30, 'reorder_level' => 0];
            $this->postJson('/api/inventory/items', $payload)->assertCreated()->assertJsonMissingPath('data.dose_unit')->assertJsonPath('data.selling_price', '30.00');
        }
        $this->getJson('/api/inventory/search?q=Clinic&sale_context=pos')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_product_strength_is_optional_and_saved_only_for_medicine(): void
    {
        $medicine = $this->item();
        $this->assertSame('10 mg/mL', $medicine->strength_concentration);
        $this->putJson('/api/inventory/items/'.$medicine->item_id, $this->product(['strength_concentration' => '50 mg/tablet']))
            ->assertOk()->assertJsonPath('data.strength_concentration', '50 mg/tablet');
        $this->assertSame('50 mg/tablet', $medicine->fresh()->strength_concentration);
        $this->postJson('/api/inventory/items', $this->product(['strength_concentration' => str_repeat('x', 151)]))
            ->assertUnprocessable()->assertJsonValidationErrors('strength_concentration');
        $optional = $this->product(); unset($optional['strength_concentration']);
        $this->postJson('/api/inventory/items', $optional)->assertCreated()->assertJsonPath('data.strength_concentration', null);

        $vaccine = $this->product(['category' => 'vaccine', 'medicine_type' => null, 'pricing_method' => 'flat',
            'weight_prices' => [], 'selling_price' => 950, 'strength_concentration' => '>4.6 log10 PFU']);
        $id = $this->postJson('/api/inventory/items', $vaccine)->assertCreated()
            ->assertJsonPath('data.strength_concentration', null)->json('data.item_id');
        DB::table('inventory_items')->where('item_id', $id)->update(['strength_concentration' => '>4.6 log10 PFU']);
        unset($vaccine['strength_concentration']);
        $this->putJson('/api/inventory/items/'.$id, $vaccine)->assertOk()->assertJsonPath('data.strength_concentration', null);
        $this->assertNull(InventoryItem::findOrFail($id)->strength_concentration);

        foreach (InventoryItem::RETAIL_CATEGORIES as $category) {
            $payload = ['item_name' => $category, 'category' => $category, 'unit' => 'box', 'unit_cost' => 20,
                'selling_price' => 30, 'reorder_level' => 0, 'strength_concentration' => '100 mg'];
            $id = $this->postJson('/api/inventory/items', $payload)->assertCreated()
                ->assertJsonPath('data.strength_concentration', null)->json('data.item_id');
            $this->assertNull(InventoryItem::findOrFail($id)->strength_concentration);
        }
        $this->putJson('/api/inventory/items/'.$medicine->item_id, $vaccine)->assertOk()
            ->assertJsonPath('data.strength_concentration', null);
        $this->assertNull($medicine->fresh()->strength_concentration);
    }

    public function test_category_and_pricing_changes_clean_ranges_only_when_saved(): void
    {
        $item = $this->item();
        $invalid = $this->product(['category' => 'vaccine', 'selling_price' => 950]);
        $this->putJson('/api/inventory/items/'.$item->item_id, $invalid)->assertUnprocessable();
        $this->assertSame(4, $item->weightPrices()->count());
        $vaccine = $this->product(['category' => 'vaccine', 'medicine_type' => null, 'pricing_method' => 'flat', 'weight_prices' => [], 'selling_price' => 950]);
        $this->putJson('/api/inventory/items/'.$item->item_id, $vaccine)->assertOk()->assertJsonPath('data.medicine_type', null)->assertJsonCount(0, 'data.weight_prices');
        $this->assertSame(0, $item->weightPrices()->count());
        $other = $this->item();
        $this->postJson('/api/inventory/items/'.$other->item_id.'/deactivate')->assertOk();
        $this->assertSame(4, $other->weightPrices()->count());
        $this->postJson('/api/inventory/items/'.$other->item_id.'/reactivate')->assertOk()->assertJsonCount(4, 'data.weight_prices');
        $other->delete();
        $this->assertSame(0, DB::table('inventory_item_weight_prices')->where('item_id', $other->item_id)->count());
    }

    public function test_clinic_prices_exact_inclusive_ranges_without_deriving_quantity(): void
    {
        $item = $this->item();
        foreach ([[1, 200], [5, 200], [5.1, 400], [10, 400], [10.1, 700], [12.4, 700], [15, 700], [41, 900]] as [$weight, $price]) {
            $appt = $this->appointment($weight);
            $entry = $this->charge($item, $price, $weight);
            $this->saveMedicineCharges($appt, [$entry])->assertOk();
            $this->assertDatabaseHas('clinic_charges', ['clinic_appointment_id' => $appt->id, 'amount' => $price, 'pet_weight_kg' => $weight, 'quantity' => 1]);
            $this->assertDatabaseCount('inventory_transactions', 0);
            $this->saveMedicineCharges($appt, [$entry])->assertOk();
            $this->assertSame(1, $appt->charges()->count());
        }
    }

    public function test_missing_zero_and_unconfigured_weight_never_guess_a_price(): void
    {
        $item = $this->item();
        foreach ([null, 0] as $weight) {
            $appt = $this->appointment($weight);
            $this->saveMedicineCharges($appt, [$this->charge($item)])->assertUnprocessable();
            $this->assertSame(0, $appt->charges()->count());
        }
        foreach ([0.5, 5.05, 10.05, 15.05, 50.01] as $weight) {
            $appt = $this->appointment($weight);
            $this->saveMedicineCharges($appt, [$this->charge($item)])
                ->assertUnprocessable()->assertJsonPath('errors.medicine.0', "No price is configured for this pet's weight. Correct the pricing configuration or pet weight.");
            $this->assertSame(0, $appt->charges()->count());
            $this->assertDatabaseCount('inventory_transactions', 0);
        }
    }

    public function test_saved_vitals_take_precedence_and_stale_prices_are_rejected_atomically(): void
    {
        $item = $this->item(); $appt = $this->appointment(4);
        $entry = $this->charge($item);
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['weight_kg' => 12.4])->assertOk();
        $medical = DB::table('clinic_records')->first();
        $this->saveMedicineCharges($appt, [$entry])->assertOk();
        $this->assertDatabaseHas('clinic_charges', ['clinic_appointment_id' => $appt->id, 'pet_weight_kg' => 12.4, 'amount' => 700]);
        $entry = $this->charge($item, 950);
        $this->saveMedicineCharges($appt, [$entry])->assertUnprocessable();
        $this->assertEquals($medical, DB::table('clinic_records')->first());
        $this->assertSame(1, $appt->charges()->count());
        $vaccine = $this->item(['category' => 'vaccine', 'medicine_type' => null, 'pricing_method' => 'flat', 'weight_prices' => [], 'selling_price' => 950]);
        $this->saveMedicineCharges($appt, [$this->charge($vaccine, 950)])->assertUnprocessable();
    }

    public function test_manual_stock_use_enforces_expiry_and_retries_do_not_deduct_twice(): void
    {
        $item = $this->item(); $appt = $this->appointment();
        $this->postJson('/api/inventory/stock-in', ['items' => [[
            'item_id' => $item->item_id, 'quantity' => 2, 'reason' => 'purchase', 'expiry_date' => today()->addYear()->toDateString(), 'batch_number' => 'M-1',
        ]]])->assertCreated();
        $entry = $this->charge($item, 700, 12.4, 1);
        $this->saveMedicineCharges($appt, [$entry])->assertOk();
        $this->saveMedicineCharges($appt, [$entry])->assertOk();
        $this->assertSame('1.00', $item->fresh()->quantity_on_hand);
        $this->assertSame(1, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->assertDatabaseHas('inventory_transactions', ['reference_type' => 'clinic', 'reference_id' => $appt->id, 'quantity' => 1, 'selling_price_at_time' => 700]);
        $this->saveMedicineCharges($appt, [$this->charge($item, 700, 12.4, 2)])->assertUnprocessable();
        DB::table('inventory_transactions')->where('type', 'stock_in')->update(['expiry_date' => today()->subDay()]);
        $this->saveMedicineCharges($appt, [$this->charge($item, 700, 12.4, 1)])->assertUnprocessable();
        $this->assertSame(1, $appt->charges()->count());
        $this->assertSame('1.00', $item->fresh()->quantity_on_hand);
    }

    public function test_options_include_inactive_medicines_for_explanation_and_clinical_permissions_apply(): void
    {
        $item = $this->item();
        $this->getJson('/api/admin/clinic-medicine-options?q=Clinic')->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(4, 'data.0.weight_prices');
        $item->update(['is_active' => false]);
        $this->getJson('/api/admin/clinic-medicine-options?q=Clinic')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active', false);
        $appt = $this->appointment(); $item->update(['is_active' => true]);
        $appt->update(['status' => 'completed']);
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['diagnosis' => 'Locked case'])->assertStatus(409);
        Sanctum::actingAs(User::create(['role' => 'customer']));
        $this->getJson('/api/admin/clinic-medicine-options?q=Clinic')->assertForbidden();
        $this->postJson('/api/admin/clinic-appointments/'.$appt->id.'/record', ['medicines' => [$this->charge($item)]])->assertForbidden();
    }

    public function test_migration_preserves_legacy_products_without_inventing_dose_units(): void
    {
        $migration = require database_path('migrations/2026_10_10_000002_add_clinic_inventory_pricing.php');
        $migration->down();
        foreach (['medicine', 'vaccine', 'food'] as $category) {
            DB::table('inventory_items')->insert(['item_name' => 'Legacy '.$category, 'category' => $category, 'unit' => 'bottle', 'unit_cost' => 20, 'selling_price' => 50]);
        }
        $migration->up();
        foreach (InventoryItem::all() as $item) {
            $this->assertSame('50.00', $item->selling_price);
            $this->assertSame('flat', $item->pricing_method);
            $this->assertNull($item->dose_unit);
            $this->assertSame($item->category === 'medicine' ? 'general_medication' : null, $item->medicine_type);
        }
        $legacy = InventoryItem::where('category', 'medicine')->first();
        $this->putJson('/api/inventory/items/'.$legacy->item_id, ['unit_cost' => 20, 'selling_price' => 50, 'reorder_level' => 0])->assertOk();
    }
}
