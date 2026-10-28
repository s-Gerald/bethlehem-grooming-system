<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\InventoryTransaction;
use App\Services\InventoryStockMovementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventorySecurityRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('password_hash');
            $table->string('role');
            $table->string('customer_tier')->default('new');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('email_verified_at')->nullable();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('item_id')->autoIncrement();
            $table->string('item_name', 150);
            $table->string('barcode', 100)->nullable()->unique();
            $table->string('category');
            $table->string('unit', 50);
            $table->string('medicine_type')->nullable();
            $table->string('dose_unit')->nullable();
            $table->string('strength_concentration')->nullable();
            $table->string('pricing_method')->default('flat');
            $table->string('weight_pricing_mode')->default('legacy');
            $table->string('description', 255)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('unit_cost', 8, 2)->default(0);
            $table->decimal('selling_price', 8, 2)->nullable();
            $table->decimal('quantity_on_hand', 10, 2)->default(0);
            $table->decimal('reorder_level', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_item_weight_prices', function (Blueprint $table) {
            $table->id(); $table->unsignedInteger('item_id');
            $table->decimal('min_weight_kg', 8, 2); $table->decimal('max_weight_kg', 8, 2)->nullable();
            $table->decimal('price', 8, 2); $table->timestamps();
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->unsignedInteger('transaction_id')->autoIncrement();
            $table->unsignedInteger('item_id');
            $table->string('type');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_cost_at_time', 8, 2)->nullable();
            $table->decimal('selling_price_at_time', 8, 2)->nullable();
            $table->string('reason');
            $table->string('batch_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('reference_type')->default('manual');
            $table->unsignedInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('performed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        (require database_path('migrations/2026_10_23_000001_add_inventory_receipt_references.php'))->up();

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->unsignedInteger('pos_id')->autoIncrement();
            $table->unsignedInteger('cashier_id');
            $table->decimal('total_amount', 8, 2);
            $table->decimal('amount_tendered', 8, 2);
            $table->decimal('change_amount', 8, 2);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pos_id');
            $table->unsignedInteger('item_id');
            $table->string('item_name')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('quantity', 10, 2);
            $table->decimal('price_at_sale', 10, 2);
            $table->decimal('subtotal', 10, 2);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pos_transaction_items');
        Schema::dropIfExists('pos_transactions');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventory_item_weight_prices');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_every_inventory_and_pos_route_requires_staff_or_admin_role(): void
    {
        $expected = [
            ['GET', 'api/inventory/items'],
            ['POST', 'api/inventory/items'],
            ['GET', 'api/inventory/items/{id}'],
            ['PUT', 'api/inventory/items/{id}'],
            ['POST', 'api/inventory/items/{id}/deactivate'],
            ['POST', 'api/inventory/items/{id}/reactivate'],
            ['GET', 'api/inventory/search'],
            ['GET', 'api/inventory/barcode/{barcode}'],
            ['POST', 'api/inventory/stock-in'],
            ['POST', 'api/inventory/stock-out'],
            ['GET', 'api/inventory/transactions'],
            ['GET', 'api/inventory/low-stock'],
            ['GET', 'api/inventory/alerts/expiry'],
            ['GET', 'api/inventory/alerts/badge'],
            ['GET', 'api/inventory/summary'],
            ['POST', 'api/pos/transactions'],
            ['POST', 'api/pos/validate-cart'],
            ['GET', 'api/pos/transactions'],
            ['GET', 'api/pos/transactions/{posId}'],
        ];

        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ($expected as [$method, $uri]) {
            $route = $routes->first(
                fn (RoutingRoute $route) => $route->uri() === $uri
                    && in_array($method, $route->methods(), true),
            );

            $this->assertNotNull($route, "Expected protected route [{$method} {$uri}] is not registered.");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('role:admin,staff', $route->gatherMiddleware());
        }

        foreach ([
            'api/inventory/suppliers',
            'api/inventory/suppliers/{id}',
            'api/inventory/suppliers/{id}/deactivate',
        ] as $uri) {
            $this->assertNull(
                $routes->first(fn (RoutingRoute $route) => $route->uri() === $uri),
                "Supplier route [{$uri}] must not be registered.",
            );
        }
    }

    public function test_customer_is_forbidden_from_inventory_and_pos_endpoints(): void
    {
        Sanctum::actingAs($this->createUser('customer', '09170000001'));

        $this->getJson('/api/inventory/items')->assertForbidden();
        $this->postJson('/api/pos/validate-cart', [])->assertForbidden();
        $this->postJson('/api/pos/transactions', [])->assertForbidden();
    }

    public function test_veterinarian_inventory_is_scoped_before_pagination_and_aggregation(): void
    {
        $vet = $this->createUser('staff', '09170000901');
        $vet->staff_type = 'clinic';
        $vet->staff_subrole = 'veterinarian';
        Sanctum::actingAs($vet);
        foreach (['medicine', 'vaccine', 'food', 'grooming_supply', 'pet_shop', 'miscellaneous'] as $category) {
            $id = $this->createInventoryItem('Scoped '.$category, 2, 100);
            DB::table('inventory_items')->where('item_id', $id)->update([
                'category' => $category, 'barcode' => 'SCOPE-'.$id, 'reorder_level' => 5,
            ]);
            $this->recordInventoryTransaction($id, 'stock_in', 2, ['expiry_date' => now()->addDays(5)->toDateString()]);
        }
        $this->getJson('/api/inventory/items')->assertOk()->assertJsonPath('total', 2)
            ->assertJsonMissingPath('data.0.unit_cost')->assertJsonMissingPath('data.0.selling_price');
        $this->getJson('/api/inventory/items?category=food&include_inactive=1')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/inventory/search?q=Scoped')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/inventory/items/3')->assertNotFound();
        $this->getJson('/api/inventory/barcode/SCOPE-3')->assertNotFound();
        $this->getJson('/api/inventory/items/1')->assertOk()->assertJsonCount(1, 'data.current_batches')
            ->assertJsonMissingPath('data.unit_cost');
        foreach (['?view=overview', ''] as $query) {
            $this->getJson('/api/inventory/summary'.$query)->assertOk()->assertJsonPath('total_items', 2)
                ->assertJsonPath('low_stock_count', 2)->assertJsonPath('expiry_alert_count', 2)
                ->assertJsonMissingPath('recent_transactions')->assertJsonMissingPath('top_used_30_days');
        }
        $this->getJson('/api/inventory/low-stock')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/inventory/alerts/expiry')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/inventory/alerts/badge')->assertOk()->assertJsonPath('total', 4);
        $this->getJson('/api/inventory/transactions')->assertOk()->assertJsonPath('total', 2)
            ->assertJsonMissingPath('data.0.unit_cost_at_time')->assertJsonMissingPath('data.0.selling_price_at_time');
        $this->getJson('/api/inventory/transactions?item_id=3')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_veterinarian_cannot_mutate_inventory_or_use_any_pos_endpoint(): void
    {
        $vet = $this->createUser('staff', '09170000902');
        $vet->staff_type = 'clinic';
        $vet->staff_subrole = 'veterinarian';
        Sanctum::actingAs($vet);
        foreach ([['post', '/inventory/items'], ['put', '/inventory/items/1'],
            ['post', '/inventory/items/1/deactivate'], ['post', '/inventory/items/1/reactivate'],
            ['post', '/inventory/stock-in'], ['post', '/inventory/stock-out'],
            ['post', '/pos/validate-cart'], ['post', '/pos/transactions'], ['get', '/pos/transactions'],
            ['get', '/pos/transactions/1'], ['post', '/pos/online-payment'],
            ['get', '/pos/online-payment/1'], ['delete', '/pos/online-payment/1']] as [$method, $path]) {
            $this->json(strtoupper($method), '/api'.$path)->assertForbidden();
        }
        $this->getJson('/api/admin/bookings/archived')->assertForbidden();
        $this->patchJson('/api/admin/clinic/settings/availability', [])->assertForbidden();
        $this->patchJson('/api/admin/clinic/settings/groomers-on-duty', [])->assertForbidden();
        $this->postJson('/api/admin/pet-groomers', [])->assertForbidden();
        $this->assertDatabaseCount('inventory_items', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_administrators_and_receptionists_keep_catalog_and_pos_permissions(): void
    {
        $this->createInventoryItem('Clinical item', 0, 100);
        $retail = $this->createInventoryItem('Retail item', 5, 100);
        DB::table('inventory_items')->where('item_id', $retail)->update(['category' => 'pet_shop']);
        foreach ([['admin', null, null], ['staff', 'clinic', 'clinic_receptionist'],
            ['staff', 'grooming', 'grooming_receptionist']] as [$role, $type, $subrole]) {
            Sanctum::actingAs(new User(['role' => $role, 'staff_type' => $type, 'staff_subrole' => $subrole, 'is_active' => true]));
            $this->getJson('/api/inventory/items')->assertOk()->assertJsonPath('total', 2);
            $this->postJson('/api/inventory/items/1/reactivate')->assertOk();
            $this->postJson('/api/pos/validate-cart', ['items' => [['item_id' => $retail, 'quantity' => 1]]])->assertOk();
        }
    }

    public function test_inventory_dashboard_summary_paginates_recent_transactions_ten_per_page(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000002'));

        $itemId = $this->createInventoryItem('Dashboard Pagination Product', 20, 100);

        foreach (range(1, 11) as $sequence) {
            $this->recordInventoryTransaction($itemId, 'stock_in', $sequence, [
                'created_at' => now()->subMinutes(11 - $sequence),
            ]);
        }

        $this->getJson('/api/inventory/summary?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'recent_transactions')
            ->assertJsonPath('recent_transactions.0.quantity', '1.00')
            ->assertJsonPath('recent_transactions_page', 2)
            ->assertJsonPath('recent_transactions_last_page', 2)
            ->assertJsonPath('recent_transactions_total', 11);
    }

    public function test_overview_summary_contains_bounded_previews_without_report_queries(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000029'));
        foreach (range(1, 7) as $sequence) {
            $itemId = $this->createInventoryItem('Preview '.$sequence, 2, 100);
            DB::table('inventory_items')->where('item_id', $itemId)->update(['reorder_level' => 5]);
            $this->recordInventoryTransaction($itemId, 'stock_in', 2, [
                'expiry_date' => now()->addDays($sequence)->toDateString(),
            ]);
        }
        DB::enableQueryLog();
        $overview = $this->getJson('/api/inventory/summary?page=1&view=overview')->assertOk()
            ->assertJsonPath('total_items', 7)->assertJsonPath('low_stock_count', 7)
            ->assertJsonPath('expiry_alert_count', 7)
            ->assertJsonCount(5, 'low_stock_items')->assertJsonCount(5, 'expiry_items')
            ->assertJsonCount(5, 'recent_transactions')->assertJsonMissingPath('top_used_30_days');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertFalse($queries->contains(fn ($sql) => str_contains(strtolower($sql), 'total_used')));
        $low = $this->getJson('/api/inventory/low-stock')->assertOk()->json('data');
        $expiry = $this->getJson('/api/inventory/alerts/expiry')->assertOk()->json('data');
        $this->assertSame(array_column(array_slice($low, 0, 5), 'item_id'), array_column($overview->json('low_stock_items'), 'item_id'));
        $this->assertSame(array_column(array_slice($expiry, 0, 5), 'item_id'), array_column($overview->json('expiry_items'), 'item_id'));
    }

    public function test_low_stock_alerts_paginate_ten_items_per_page(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000015'));

        foreach (range(1, 11) as $sequence) {
            $itemId = $this->createInventoryItem("Low Stock {$sequence}", 1, 2);
            DB::table('inventory_items')
                ->where('item_id', $itemId)
                ->update(['reorder_level' => 2]);
        }

        $this->getJson('/api/inventory/low-stock?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('page', 2)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('total', 11)
            ->assertJsonPath('count', 11);
    }

    public function test_expiry_alerts_paginate_the_calculated_records_ten_per_page(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000016'));

        foreach (range(1, 11) as $sequence) {
            $itemId = $this->createInventoryItem("Expiry Alert {$sequence}", 1, 0);
            $this->recordInventoryTransaction($itemId, 'stock_in', 1, [
                'batch_number' => "EXP-{$sequence}",
                'expiry_date' => now()->addDays($sequence)->toDateString(),
            ]);
        }

        $this->getJson('/api/inventory/alerts/expiry?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('page', 2)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('total', 11)
            ->assertJsonPath('count', 11);
    }

    public function test_transaction_history_supports_the_dashboard_ten_row_page_size(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000017'));

        $itemId = $this->createInventoryItem('Dashboard Transaction Page', 20, 0);
        foreach (range(1, 11) as $sequence) {
            $this->recordInventoryTransaction($itemId, 'stock_in', $sequence, [
                'created_at' => now()->subMinutes(11 - $sequence),
            ]);
        }

        $this->getJson('/api/inventory/transactions?page=2&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', '1.00')
            ->assertJsonPath('page', 2)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('total', 11);
    }

    public function test_stock_movement_actor_is_shown_in_transaction_history_and_dashboard(): void
    {
        $actor = $this->createUser('staff', '09170000020');
        Sanctum::actingAs($actor);

        $itemId = $this->createInventoryItem('Actor-tracked Product', 0, 100);

        $this->postJson('/api/inventory/stock-in', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 2,
                'reason' => 'purchase',
                'batch_number' => 'ACTOR-LOT',
                'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ])->assertCreated();

        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 1,
                'reason' => 'used',
            ]],
        ])->assertCreated();

        $actorDetails = [
            'performed_by' => $actor->user_id,
            'performed_by_name' => 'Staff Inventory Tester',
        ];

        $history = $this->getJson('/api/inventory/transactions')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $summary = $this->getJson('/api/inventory/summary')
            ->assertOk()
            ->assertJsonCount(2, 'recent_transactions');

        foreach ([$history->json('data'), $summary->json('recent_transactions')] as $transactions) {
            $this->assertSame(
                ['stock_in', 'stock_out'],
                collect($transactions)
                    ->filter(fn (array $transaction) => $transaction['performed_by'] === $actorDetails['performed_by']
                        && $transaction['performed_by_name'] === $actorDetails['performed_by_name'])
                    ->pluck('type')
                    ->sort()
                    ->values()
                    ->all(),
            );
        }
    }

    public function test_staff_can_process_a_pos_sale_under_the_inventory_role_contract(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000014'));

        $itemId = $this->createInventoryItem('Staff POS Product', 1, 125.50);
        DB::table('inventory_items')->where('item_id', $itemId)->update(['category' => 'food']);
        $this->recordInventoryTransaction($itemId, 'stock_in', 1, [
            'batch_number' => 'STAFF-POS-BATCH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->postJson('/api/pos/transactions', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 1,
            ]],
            'amount_tendered' => 200,
        ])->assertCreated()
            ->assertJsonPath('data.total_amount', '125.50')
            ->assertJsonPath('data.change_amount', '74.50');

        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'quantity_on_hand' => 0,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_out',
            'reason' => 'sold',
            'reference_type' => 'pos',
        ]);
    }

    public function test_inventory_search_can_include_deactivated_products_when_requested(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000019'));

        $activeId = $this->createInventoryItem('Searchable Active Product', 0, 50);
        $inactiveId = $this->createInventoryItem('Searchable Deactivated Product', 0, 50);
        DB::table('inventory_items')->where('item_id', $inactiveId)->update(['is_active' => false]);

        $this->getJson('/api/inventory/search?q=Searchable')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_id', $activeId);

        $this->getJson('/api/inventory/search?q=Searchable&include_inactive=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['item_id' => $inactiveId, 'is_active' => false]);
    }

    public function test_product_has_no_misleading_expiry_and_stock_in_validates_batch_expiry(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000002'));

        $itemId = $this->postJson('/api/inventory/items', [
            'item_name' => 'Canine Vaccine',
            'category' => 'vaccine',
            'dose_unit' => 'dose',
            'unit' => 'vial',
            'unit_cost' => 100,
            'selling_price' => 150,
            'reorder_level' => 2,
        ])->assertCreated()
            ->assertJsonMissingPath('data.expiry_date')
            ->json('data.item_id');

        $this->postJson('/api/inventory/stock-in', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 5,
                'reason' => 'purchase',
                'batch_number' => 'EXPIRY-LOT',
                'expiry_date' => now()->subDay()->toDateString(),
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.expiry_date']);

        $expiryDate = now()->addYear()->toDateString();

        $this->postJson('/api/inventory/stock-in', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 5,
                'reason' => 'purchase',
                'batch_number' => 'BATCH-001',
                'expiry_date' => $expiryDate,
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'quantity_on_hand' => 5,
        ]);
        $this->assertTrue(
            DB::table('inventory_transactions')
                ->where('item_id', $itemId)
                ->where('type', 'stock_in')
                ->where('batch_number', 'BATCH-001')
                ->whereDate('expiry_date', $expiryDate)
                ->exists(),
        );

        $this->postJson('/api/inventory/stock-in', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 1.5,
                'reason' => 'purchase',
                'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity']);

        foreach (['pet_shop', 'miscellaneous'] as $category) {
            $itemId = $this->postJson('/api/inventory/items', [
                'item_name' => "Non-expiring {$category} item",
                'category' => $category,
                'unit' => 'piece',
                'unit_cost' => 100,
                'selling_price' => 150,
                'reorder_level' => 2,
            ])->assertCreated()->json('data.item_id');

            $this->postJson('/api/inventory/stock-in', [
                'items' => [[
                    'item_id' => $itemId,
                    'quantity' => 1,
                    'reason' => 'purchase',
                    'expiry_date' => now()->addYear()->toDateString(),
                ]],
            ])->assertCreated();

            $this->assertDatabaseHas('inventory_transactions', [
                'item_id' => $itemId,
                'type' => 'stock_in',
                'expiry_date' => null,
            ]);

            $this->postJson('/api/inventory/stock-out', [
                'items' => [[
                    'item_id' => $itemId,
                    'quantity' => 1,
                    'reason' => 'expired',
                ]],
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(['items.0.reason']);
        }
    }

    public function test_grooming_search_keeps_only_eligible_products_including_those_without_sellable_stock(): void
    {
        $create = function (string $name, string $category, float $stock, ?float $price = 250, bool $active = true): int {
            $id = $this->createInventoryItem($name, $stock, $price);
            DB::table('inventory_items')->where('item_id', $id)->update([
                'category' => $category, 'is_active' => $active,
            ]);

            return $id;
        };

        $empty = $create('Search A Empty Shampoo', 'grooming_supply', 0);
        $expired = $create('Search B Expired Shampoo', 'grooming_supply', 4);
        $this->recordInventoryTransaction($expired, 'stock_in', 4, ['expiry_date' => now()->subDay()->toDateString()]);
        $unknown = $create('Search C Unknown Expiry Food', 'food', 3);
        $mixed = $create('Search D Mixed Shampoo', 'grooming_supply', 6);
        $this->recordInventoryTransaction($mixed, 'stock_in', 4, ['expiry_date' => now()->subDay()->toDateString()]);
        $this->recordInventoryTransaction($mixed, 'stock_in', 2, ['expiry_date' => now()->toDateString()]);
        $physical = $create('Search E Physical Limit Food', 'food', 1);
        DB::table('inventory_items')->where('item_id', $physical)->update(['reorder_level' => 15]);
        $this->recordInventoryTransaction($physical, 'stock_in', 5, ['expiry_date' => now()->addYear()->toDateString()]);
        $shop = $create('Search F Pet Shop', 'pet_shop', 3);
        $misc = $create('Search G Miscellaneous', 'miscellaneous', 2);
        $depleted = $create('Search H Depleted Food', 'food', 2);
        $this->recordInventoryTransaction($depleted, 'stock_in', 2, ['expiry_date' => now()->addYear()->toDateString()]);
        $this->recordInventoryTransaction($depleted, 'stock_out', 2, ['reason' => 'sold']);
        $create('Search Medicine', 'medicine', 4);
        $create('Search Vaccine', 'vaccine', 4);
        $inactive = $create('Search Inactive', 'pet_shop', 4, 250, false);
        $unpriced = $create('Search Unpriced', 'pet_shop', 4, null);
        $create('Unrelated Product', 'pet_shop', 4);

        foreach (['admin', 'staff'] as $role) {
            Sanctum::actingAs($this->createUser($role, $role === 'admin' ? '09170000019' : '09170000020'));
            $data = $this->getJson('/api/inventory/search?q=Search&sale_context=grooming&include_inactive=1')
                ->assertOk()->assertJsonCount(10, 'data')->json('data');

            $this->assertSame([$mixed, $physical, $shop, $misc, $empty, $expired, $unknown, $depleted, $inactive, $unpriced], array_column($data, 'item_id'));
            $this->assertEquals([2, 1, 3, 2, 0, 0, 0, 0, 4, 4], array_column($data, 'saleable_quantity'));
            $this->assertEquals(250, $data[0]['selling_price']);
            $this->assertTrue($data[1]['low_stock']);
            $this->assertEquals(1, $data[1]['quantity_on_hand']);
            $this->assertEquals(4, $data[5]['expired_quantity']);
            $this->assertFalse($data[8]['is_active']);
            $activeResults = $this->getJson('/api/inventory/search?q=Search&sale_context=grooming')
                ->assertOk()->assertJsonCount(9, 'data')->json('data');
            $this->assertNotContains($inactive, array_column($activeResults, 'item_id'));
            $this->getJson('/api/inventory/search?q=DoesNotExist&sale_context=grooming')
                ->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_grooming_search_prioritizes_available_products_before_the_result_limit(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000021'));
        foreach (range(1, 60) as $number) {
            $id = $this->createInventoryItem(sprintf('Search A Empty %02d', $number), 0, 50);
            DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop']);
        }
        $available = $this->createInventoryItem('Search Z Available', 3, 50);
        DB::table('inventory_items')->where('item_id', $available)->update(['category' => 'pet_shop']);

        $this->getJson('/api/inventory/search?q=Search&sale_context=grooming')
            ->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.item_id', $available);
    }

    public function test_product_crud_requires_positive_prices_a_numeric_barcode_and_an_integer_minimum_stock(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000017'));

        $basePayload = [
            'item_name' => 'Validated Product',
            'medicine_type' => 'general_medication', 'dose_unit' => 'tablet', 'pricing_method' => 'flat',
            'category' => 'medicine',
            'unit' => 'piece',
        ];

        $this->postJson('/api/inventory/items', [
            ...$basePayload,
            'barcode' => 'ABC-123',
            'unit_cost' => 0,
            'selling_price' => 10.999,
            'reorder_level' => 1.5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['barcode', 'unit_cost', 'selling_price', 'reorder_level']);

        $this->postJson('/api/inventory/items', $basePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_cost', 'selling_price', 'reorder_level']);

        $this->postJson('/api/inventory/items', [
            ...$basePayload,
            'barcode' => '0012345678901',
            'unit_cost' => 0,
            'selling_price' => 2.50,
            'reorder_level' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_cost']);

        $this->postJson('/api/inventory/items', [
            ...$basePayload,
            'barcode' => '0012345678901',
            'unit_cost' => 1.25,
            'selling_price' => 0,
            'reorder_level' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['selling_price']);

        $this->postJson('/api/inventory/items', [
            ...$basePayload,
            'barcode' => '0012345678901',
            'unit_cost' => 1.25,
            'selling_price' => 2.50,
            'reorder_level' => 1.5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reorder_level']);

        $this->postJson('/api/inventory/items', [
            ...$basePayload,
            'barcode' => '0012345678901',
            'unit_cost' => 1.25,
            'selling_price' => 2.50,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reorder_level']);

        $this->postJson('/api/inventory/items', [
            ...$basePayload,
            'barcode' => '00123456789012',
            'unit_cost' => 1.25,
            'selling_price' => 2.50,
            'reorder_level' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['barcode']);

        $itemId = $this->postJson('/api/inventory/items', [
            ...$basePayload,
            'barcode' => '0012345678901',
            'unit_cost' => 1.25,
            'selling_price' => 2.50,
            'reorder_level' => 0,
        ])->assertCreated()
            ->json('data.item_id');

        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'barcode' => '0012345678901',
        ]);

        $legacyItemId = $this->createInventoryItem('Legacy Price Product', 0, null);
        $this->putJson("/api/inventory/items/{$legacyItemId}", [
            ...$basePayload,
            'unit_cost' => 0,
            'selling_price' => null,
            'reorder_level' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_cost', 'selling_price']);
    }

    public function test_product_list_returns_exactly_ten_items_per_page_and_the_filtered_total(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000018'));

        foreach (range(1, 16) as $number) {
            $this->createInventoryItem("Paged Product {$number}", 0, 10);
        }
        $this->createInventoryItem('Nonmatching Product', 0, 10);

        $this->getJson('/api/inventory/items?page=1')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 17)
            ->assertJsonPath('last_page', 2);

        $this->getJson('/api/inventory/items?q=Paged%20Product&page=2')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('total', 16)
            ->assertJsonPath('last_page', 2);
    }

    public function test_stock_movement_service_records_stock_in_with_a_batched_balance_projection(): void
    {
        $actor = $this->createUser('admin', '09170000015');
        $itemId = $this->createInventoryItem('Service Stock-In Product', 0, 75);

        $results = DB::transaction(fn () => app(InventoryStockMovementService::class)->receive(
            $actor,
            [[
                'item_id' => $itemId,
                'quantity' => 3,
                'reason' => 'purchase',
                'batch_number' => 'SERVICE-IN-BATCH',
                'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ));

        $this->assertEquals(3.0, $results[0]['quantity_added']);
        $this->assertEquals(3.0, $results[0]['quantity_on_hand']);
        $this->assertEquals(3.0, $results[0]['unexpired_quantity']);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_in',
            'reason' => 'purchase',
            'batch_number' => 'SERVICE-IN-BATCH',
            'performed_by' => $actor->user_id,
        ]);
    }

    public function test_stock_movement_service_records_stock_out_and_preserves_the_ledger_reference(): void
    {
        $actor = $this->createUser('admin', '09170000016');
        $itemId = $this->createInventoryItem('Service Stock-Out Product', 3, 75);
        $this->recordInventoryTransaction($itemId, 'stock_in', 3, [
            'batch_number' => 'SERVICE-OUT-BATCH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $results = DB::transaction(fn () => app(InventoryStockMovementService::class)->remove(
            $actor,
            [[
                'item_id' => $itemId,
                'quantity' => 1,
                'reason' => 'used',
                'reference_type' => 'appointment',
                'reference_id' => 42,
            ]],
        ));

        $this->assertEquals(1.0, $results[0]['quantity_removed']);
        $this->assertEquals(2.0, $results[0]['quantity_on_hand']);
        $this->assertEquals(2.0, $results[0]['unexpired_quantity']);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_out',
            'reason' => 'used',
            'reference_type' => 'appointment',
            'reference_id' => 42,
            'performed_by' => $actor->user_id,
        ]);
    }

    public function test_inventory_pages_use_the_shared_api_and_versionable_assets(): void
    {
        $service = file_get_contents(base_path('scripts/services/inventory-service.js'));

        $this->assertStringContainsString('API.adminRequest(method, endpoint, body)', $service);
        $this->assertStringNotContainsString('127.0.0.1:8000', $service);

        $page = file_get_contents(base_path('pages/admin/inventory/inventory.html'));
        foreach (['api.js', 'services/inventory-service.js', 'components/admin-sidebar.js', 'components/admin-inventory.js'] as $script) {
            $this->assertMatchesRegularExpression(
                '~<script\b[^>]*src="(?:\.\./)+scripts/'.preg_quote($script, '~').'(?:\?[^"#]+)?"[^>]*>~',
                $page,
            );
        }
        $this->assertStringContainsString('inventory.html#sell-product', file_get_contents(base_path('pages/admin/inventory/pos.html')));

        $inventoryLoader = file_get_contents(base_path('scripts/components/admin-inventory.js'));
        $posPage = file_get_contents(base_path('pages/admin/inventory/pos.html'));
        $stockOutScript = file_get_contents(base_path('scripts/components/admin-stock-out.js'));

        $this->assertMatchesRegularExpression('~scripts/components/\$\{name\}(?:\?[^`\s]+)?`~', $inventoryLoader);
        foreach ([
            'overview' => 'admin-inventory-dashboard.js',
            'products' => 'admin-inventory-items.js',
            'stock-in' => 'admin-stock-in.js',
            'stock-out' => 'admin-stock-out.js',
            'history' => 'admin-inventory-transactions.js',
        ] as $section => $script) {
            $this->assertFileExists(base_path("pages/admin/inventory/sections/{$section}.html"));
            $this->assertStringContainsString($script, $inventoryLoader);
        }
        $this->assertStringContainsString('"success-toast.js"', $inventoryLoader);
        $this->assertStringContainsString('admin-pos.js', $inventoryLoader);
        $this->assertFileExists(base_path('pages/admin/inventory/sections/sell-product.html'));
        $this->assertStringContainsString(
            'p.item_id === this.selected.item_id && p.reason === this.reason',
            $stockOutScript,
        );
        $this->assertStringContainsString('physicalQuantity - pendingQuantity', $stockOutScript);
        $this->assertStringNotContainsString('unexpired_quantity ?? 0', $stockOutScript);
        $this->assertStringNotContainsString('expired_quantity ?? 0', $stockOutScript);
    }

    public function test_exhausted_expired_batch_does_not_create_a_false_alert_after_restock(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000003'));

        $itemId = $this->createInventoryItem('FEFO Vaccine', 10, 150);
        $this->recordInventoryTransaction($itemId, 'stock_in', 5, [
            'batch_number' => 'OLD-EXPIRED',
            'expiry_date' => now()->subMonth()->toDateString(),
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_out', 5, [
            'reason' => 'expired',
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_in', 10, [
            'batch_number' => 'FRESH-BATCH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->getJson("/api/inventory/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('data.quantity_on_hand', '10.00')
            ->assertJsonPath('data.unexpired_quantity', 10)
            ->assertJsonPath('data.expired_quantity', 0);

        $this->getJson('/api/inventory/alerts/expiry')
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/inventory/alerts/badge')
            ->assertOk()
            ->assertJsonPath('expiry_alert_count', 0);

        $this->getJson('/api/inventory/summary')
            ->assertOk()
            ->assertJsonPath('expiry_alert_count', 0);
    }

    public function test_manual_stock_out_uses_physical_stock_while_pos_rejects_expired_stock(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000004'));

        $itemId = $this->createInventoryItem('Expired Medicine', 5, 75);
        DB::table('inventory_items')->where('item_id', $itemId)->update(['category' => 'food']);
        $this->recordInventoryTransaction($itemId, 'stock_in', 5, [
            'batch_number' => 'EXPIRED-ONLY',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);

        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 1,
                'reason' => 'used',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.0.quantity_on_hand', '4.00');

        $this->postJson('/api/pos/transactions', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 1,
                'price_at_sale' => 1,
            ]],
            'total_amount' => 1,
            'amount_tendered' => 100,
            'change_amount' => 99,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Insufficient unexpired stock for "Expired Medicine". Unexpired available: 0 piece; physical stock: 4.00 piece.');

        $this->assertDatabaseCount('pos_transactions', 0);
        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 4,
                'reason' => 'expired',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.0.unexpired_quantity', 0);

        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'quantity_on_hand' => 0,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_out',
            'reason' => 'expired',
            'quantity' => 4,
        ]);
    }

    public function test_pos_uses_server_prices_totals_and_change_even_when_client_values_are_tampered(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000005'));

        $itemId = $this->createInventoryItem('Priced Product', 3, 125.50);
        DB::table('inventory_items')->where('item_id', $itemId)->update(['category' => 'food']);
        $this->recordInventoryTransaction($itemId, 'stock_in', 3, [
            'batch_number' => 'SALE-BATCH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $tamperedPayload = [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 2,
                'price_at_sale' => 1,
            ]],
            'total_amount' => 2,
            'amount_tendered' => 2,
            'change_amount' => 0,
        ];

        $this->postJson('/api/pos/transactions', $tamperedPayload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount_tendered.0', 'Amount paid cannot be less than the final price.');
        $this->assertDatabaseCount('pos_transactions', 0);

        $tamperedPayload['amount_tendered'] = 300;
        $tamperedPayload['change_amount'] = 298;

        $response = $this->postJson('/api/pos/transactions', $tamperedPayload)
            ->assertCreated()
            ->assertJsonPath('data.total_amount', '251.00')
            ->assertJsonPath('data.amount_tendered', '300.00')
            ->assertJsonPath('data.change_amount', '49.00')
            ->assertJsonPath('data.items.0.price_at_sale', '125.50')
            ->assertJsonPath('data.items.0.subtotal', '251.00');

        $posId = $response->json('data.pos_id');
        $this->assertDatabaseHas('pos_transactions', [
            'pos_id' => $posId,
            'total_amount' => 251,
            'amount_tendered' => 300,
            'change_amount' => 49,
        ]);
        $this->assertDatabaseHas('pos_transaction_items', [
            'pos_id' => $posId,
            'item_id' => $itemId,
            'quantity' => 2,
            'price_at_sale' => 125.50,
            'subtotal' => 251,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_out',
            'reason' => 'sold',
            'selling_price_at_time' => 125.50,
            'reference_type' => 'pos',
            'reference_id' => $posId,
        ]);
    }

    public function test_manual_stock_out_uses_remaining_physical_stock_after_fresh_stock_is_used(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000006'));

        $itemId = $this->createInventoryItem('Mixed Expiry Stock', 10, 40);
        $this->recordInventoryTransaction($itemId, 'stock_in', 5, [
            'batch_number' => 'EXPIRED-MIX',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_in', 5, [
            'batch_number' => 'FRESH-MIX',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 5,
                'reason' => 'used',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.0.quantity_on_hand', '5.00')
            ->assertJsonPath('data.0.unexpired_quantity', 0);

        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 1,
                'reason' => 'used',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.0.quantity_on_hand', '4.00');

        $this->getJson("/api/inventory/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('data.unexpired_quantity', 0)
            ->assertJsonPath('data.expired_quantity', 4);
    }

    public function test_pos_rejects_an_item_without_a_server_selling_price(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000007'));

        $itemId = $this->createInventoryItem('Unpriced Product', 1, null);
        DB::table('inventory_items')->where('item_id', $itemId)->update(['category' => 'food']);
        $this->recordInventoryTransaction($itemId, 'stock_in', 1, [
            'batch_number' => 'UNPRICED-BATCH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->postJson('/api/pos/transactions', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 1,
                'price_at_sale' => 0,
            ]],
            'total_amount' => 0,
            'amount_tendered' => 100,
            'change_amount' => 100,
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'A positive selling price must be set for "Unpriced Product" before it can be sold.',
            );

        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'quantity_on_hand' => 1,
        ]);
    }

    public function test_historical_unsafe_sale_is_reconciled_without_reviving_an_expired_alert(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000008'));

        $itemId = $this->createInventoryItem('Historical Sale Stock', 10, 90);
        $this->recordInventoryTransaction($itemId, 'stock_in', 5, [
            'batch_number' => 'HISTORICAL-EXPIRED',
            'expiry_date' => now()->subMonth()->toDateString(),
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_out', 5, [
            'reason' => 'sold',
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_in', 10, [
            'batch_number' => 'HISTORICAL-FRESH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->getJson("/api/inventory/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('data.unexpired_quantity', 10)
            ->assertJsonPath('data.expired_quantity', 0)
            ->assertJsonPath('data.historically_unsafe_quantity', 5);

        $this->getJson('/api/inventory/alerts/expiry')
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_expiry_alert_reports_the_earliest_remaining_batch(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000011'));

        $itemId = $this->createInventoryItem('Out-of-order Expiry Stock', 5, 90);
        $this->recordInventoryTransaction($itemId, 'stock_in', 3, [
            'batch_number' => 'RECEIVED-FIRST',
            'expiry_date' => now()->addDays(20)->toDateString(),
        ]);
        $expiredDate = now()->subDay()->toDateString();
        $this->recordInventoryTransaction($itemId, 'stock_in', 2, [
            'batch_number' => 'RECEIVED-LATER-EXPIRED',
            'expiry_date' => $expiredDate,
        ]);

        $this->getJson('/api/inventory/alerts/expiry')
            ->assertOk()
            ->assertJsonPath('data.0.earliest_expiry', $expiredDate)
            ->assertJsonPath('data.0.is_expired', true)
            ->assertJsonPath('data.0.expiring_batches.0.batch_number', 'RECEIVED-LATER-EXPIRED');
    }

    public function test_manual_expired_writeoff_uses_physical_stock(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000009'));

        $itemId = $this->createInventoryItem('Writeoff Stock', 5, 25);
        $this->recordInventoryTransaction($itemId, 'stock_in', 2, [
            'batch_number' => 'WRITEOFF-EXPIRED',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_in', 3, [
            'batch_number' => 'WRITEOFF-FRESH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 3,
                'reason' => 'expired',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.0.quantity_on_hand', '2.00');

        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 2,
                'reason' => 'damaged',
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'quantity_on_hand' => 0,
        ]);
    }

    public function test_pos_rejects_a_server_calculated_total_that_exceeds_decimal_capacity(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000010'));

        $itemId = $this->createInventoryItem('Bulk Product', 10000, 125.50);
        DB::table('inventory_items')->where('item_id', $itemId)->update(['category' => 'food']);
        $this->recordInventoryTransaction($itemId, 'stock_in', 10000, [
            'batch_number' => 'BULK-BATCH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->postJson('/api/pos/transactions', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 10000,
                'price_at_sale' => 1,
            ]],
            'total_amount' => 1,
            'amount_tendered' => 999999.99,
            'change_amount' => 999998.99,
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'The sale exceeds the maximum supported transaction amount of ₱999,999.99.',
            );

        $this->assertDatabaseCount('pos_transactions', 0);
    }

    public function test_stock_and_money_inputs_cannot_create_subcent_ledger_drift(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000012'));

        $itemId = $this->createInventoryItem('Precision-safe Product', 1, 25);
        $this->recordInventoryTransaction($itemId, 'stock_in', 1, [
            'batch_number' => 'PRECISION-BATCH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->postJson('/api/inventory/stock-in', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 0.015,
                'reason' => 'purchase',
                'unit_cost' => 1.005,
                'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity', 'items.0.unit_cost']);

        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 0.015,
                'reason' => 'used',
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity']);

        foreach ([0.04, 1.5, 2.25] as $quantity) {
            $this->postJson('/api/inventory/stock-out', [
                'items' => [[
                    'item_id' => $itemId,
                    'quantity' => $quantity,
                    'reason' => 'used',
                ]],
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(['items.0.quantity']);
        }

        $this->postJson('/api/pos/transactions', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 0.015,
                'price_at_sale' => 25,
            ]],
            'amount_tendered' => 25.001,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity', 'amount_tendered']);

        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'quantity_on_hand' => 1,
        ]);
        $this->assertDatabaseMissing('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_out',
        ]);
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    public function test_mixed_writeoffs_are_safe_regardless_of_client_row_order(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000013'));

        $itemId = $this->createInventoryItem('Mixed Writeoff Order', 5, 25);
        $this->recordInventoryTransaction($itemId, 'stock_in', 2, [
            'batch_number' => 'ORDER-EXPIRED',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_in', 3, [
            'batch_number' => 'ORDER-FRESH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->postJson('/api/inventory/stock-out', [
            'items' => [
                [
                    'item_id' => $itemId,
                    'quantity' => 3,
                    'reason' => 'damaged',
                ],
                [
                    'item_id' => $itemId,
                    'quantity' => 2,
                    'reason' => 'expired',
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'quantity_on_hand' => 0,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_out',
            'reason' => 'expired',
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_out',
            'reason' => 'damaged',
            'quantity' => 3,
        ]);
    }

    public function test_product_details_uses_fefo_balances_and_keeps_expired_receipts_visible(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000030'));
        $itemId = $this->createInventoryItem('Mixed expiry vaccine', 17, 150);
        $exhausted = $this->recordInventoryTransaction($itemId, 'stock_in', 10, [
            'expiry_date' => now()->addDay()->toDateString(),
        ]);
        $expired = $this->recordInventoryTransaction($itemId, 'stock_in', 3, [
            'batch_number' => 'LOT-X731',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
        $soon = $this->recordInventoryTransaction($itemId, 'stock_in', 8, [
            'expiry_date' => now()->addDays(30)->toDateString(),
        ]);
        $valid = $this->recordInventoryTransaction($itemId, 'stock_in', 6, [
            'expiry_date' => now()->addDays(31)->toDateString(),
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_out', 10, ['reason' => 'sold']);

        $details = $this->getJson("/api/inventory/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('data.tracks_expiry', true)
            ->assertJsonPath('data.quantity_on_hand', '17.00')
            ->assertJsonPath('data.saleable_quantity', 14)
            ->assertJsonPath('data.expired_quantity', 3)
            ->assertJsonCount(3, 'data.current_batches')
            ->assertJsonPath('data.current_batches.0.transaction_id', $expired)
            ->assertJsonPath('data.current_batches.0.expiry_status', 'Expired')
            ->assertJsonPath('data.current_batches.0.batch_number', 'LOT-X731')
            ->assertJsonPath('data.current_batches.1.transaction_id', $soon)
            ->assertJsonPath('data.current_batches.1.expiry_status', 'Expiring Soon')
            ->assertJsonPath('data.current_batches.1.batch_number', null)
            ->assertJsonPath('data.current_batches.2.transaction_id', $valid)
            ->assertJsonPath('data.current_batches.2.expiry_status', 'Valid');

        foreach ($details->json('data.current_batches') as $batch) {
            $this->assertSame(sprintf('SI-%s-%05d', now()->format('Y'), $batch['transaction_id']), $batch['stock_in_reference']);
        }
        $this->getJson("/api/inventory/transactions?item_id={$itemId}")
            ->assertOk()->assertJsonFragment(['transaction_id' => $exhausted]);

        $this->postJson('/api/inventory/stock-out', ['items' => [[
            'item_id' => $itemId, 'quantity' => 2, 'reason' => 'sold',
        ]]])->assertCreated();
        $this->getJson("/api/inventory/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('data.quantity_on_hand', '15.00')
            ->assertJsonPath('data.saleable_quantity', 12)
            ->assertJsonPath('data.current_batches.1.remaining_quantity', 6);
    }

    public function test_stock_in_references_are_automatic_and_independent_of_optional_batch_numbers(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000031'));
        $itemId = $this->createInventoryItem('Vaccine without manufacturer lot', 0, 150);
        $entry = ['item_id' => $itemId, 'quantity' => 20, 'reason' => 'purchase'];
        $receipts = $this->postJson('/api/inventory/stock-in', ['items' => [
            [...$entry, 'expiry_date' => now()->addMonths(5)->toDateString(), 'batch_unavailable_reason' => 'Manufacturer did not supply a lot'],
            [...$entry, 'quantity' => 15, 'expiry_date' => now()->addMonths(10)->toDateString(), 'batch_unavailable_reason' => 'Manufacturer did not supply a lot'],
            [...$entry, 'quantity' => 2, 'expiry_date' => now()->addMonths(10)->toDateString(), 'batch_number' => 'ABR240912'],
        ]])->assertCreated()->json('data');

        $references = array_column($receipts, 'stock_in_reference');
        $this->assertCount(3, array_unique($references));
        foreach ($receipts as $receipt) {
            $this->assertMatchesRegularExpression('/^VACMANLO-[A-HJ-NP-Z2-9]{10}$/', $receipt['stock_in_reference']);
            $this->assertDatabaseHas('inventory_transactions', ['transaction_id' => $receipt['transaction_id'], 'stock_in_reference' => $receipt['stock_in_reference']]);
        }
        $this->assertDatabaseHas('inventory_transactions', ['transaction_id' => $receipts[0]['transaction_id'], 'batch_number' => null]);
        $this->assertDatabaseHas('inventory_transactions', ['transaction_id' => $receipts[2]['transaction_id'], 'batch_number' => 'ABR240912']);
        $this->getJson("/api/inventory/items/{$itemId}")->assertOk()->assertJsonCount(3, 'data.current_batches');
        $history = $this->getJson("/api/inventory/transactions?item_id={$itemId}")->assertOk();
        foreach ($references as $reference) {
            $history->assertJsonFragment(['stock_in_reference' => $reference]);
        }

        $this->travel(1)->years();
        $this->getJson("/api/inventory/items/{$itemId}")->assertOk()
            ->assertJsonPath('data.current_batches.0.stock_in_reference', $references[0]);
        $this->travelBack();
    }

    public function test_medical_receipts_require_a_lot_or_documented_unavailability_and_reject_reference_overrides(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000091'));
        foreach (['medicine', 'vaccine'] as $category) {
            $id = $this->createInventoryItem('Medical product', 0, 50);
            DB::table('inventory_items')->where('item_id', $id)->update(['category' => $category]);
            $entry = ['item_id' => $id, 'quantity' => 1, 'reason' => 'purchase', 'expiry_date' => now()->addYear()->toDateString()];
            foreach ([[], ['batch_number' => 'N/A'], ['batch_number' => 'UNKNOWN'], ['batch_unavailable_reason' => ' ']] as $fields) {
                $this->postJson('/api/inventory/stock-in', ['items' => [[...$entry, ...$fields]]])
                    ->assertUnprocessable()->assertJsonValidationErrors('items.0.batch_number');
            }
            $this->postJson('/api/inventory/stock-in', ['items' => [[...$entry, 'batch_number' => 'LOT-REAL', 'stock_in_reference' => 'MY-REF']]])
                ->assertUnprocessable()->assertJsonValidationErrors('items.0.stock_in_reference');
            $this->postJson('/api/inventory/stock-in', ['items' => [[...$entry, 'batch_number' => 'LOT-REAL', 'batch_unavailable_reason' => 'Not supplied']]])
                ->assertUnprocessable()->assertJsonValidationErrors('items.0.batch_unavailable_reason');
            $receipt = $this->postJson('/api/inventory/stock-in', ['items' => [[...$entry, 'batch_unavailable_reason' => 'Manufacturer label has no lot']]])
                ->assertCreated()->json('data.0');
            $this->assertDatabaseHas('inventory_transactions', ['transaction_id' => $receipt['transaction_id'], 'batch_number' => null, 'batch_unavailable_reason' => 'Manufacturer label has no lot']);
            $this->getJson('/api/inventory/items/'.$id)->assertOk()->assertJsonPath('data.current_batches.0.batch_unavailable_reason', 'Manufacturer label has no lot');
            $this->getJson('/api/inventory/transactions?item_id='.$id)->assertOk()->assertJsonPath('data.0.batch_unavailable_reason', 'Manufacturer label has no lot');
        }
    }

    public function test_receipt_prefix_uses_product_words_and_identifiers_with_readable_random_suffixes(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000096'));
        foreach (['Antibiotic' => 'ANTIBIOT', '2-in-1 Distemper Parvo' => '21DISPAR', 'Dog Collar Large' => 'DOGCOLLA'] as $name => $prefix) {
            $id = $this->createInventoryItem($name, 0, 50);
            DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop']);
            $entry = ['item_id' => $id, 'quantity' => 1, 'reason' => 'purchase'];
            $receipts = $this->postJson('/api/inventory/stock-in', ['items' => [$entry, $entry]])->assertCreated()->json('data');
            foreach ($receipts as $receipt) $this->assertMatchesRegularExpression('/^'.$prefix.'-[A-HJ-NP-Z2-9]{10}$/', $receipt['stock_in_reference']);
            $this->assertNotSame($receipts[0]['stock_in_reference'], $receipts[1]['stock_in_reference']);
        }
    }

    public function test_receipt_reference_survives_rename_and_model_updates_cannot_replace_it(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000092'));
        $id = $this->createInventoryItem('Dog Collar', 0, 50);
        DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop']);
        $legacy = $this->recordInventoryTransaction($id, 'stock_in', 1, ['transaction_id' => 57, 'created_at' => '2026-01-02 10:00:00']);
        $reference = $this->postJson('/api/inventory/stock-in', ['items' => [['item_id' => $id, 'quantity' => 1, 'reason' => 'purchase']]])
            ->assertCreated()->json('data.0.stock_in_reference');
        $this->assertMatchesRegularExpression('/^DOGCOL-[A-HJ-NP-Z2-9]{10}$/', $reference);
        DB::table('inventory_items')->where('item_id', $id)->update(['item_name' => 'Renamed']);
        $this->assertSame('SI-2026-00057', InventoryTransaction::findOrFail($legacy)->stock_in_reference);
        $transaction = InventoryTransaction::where('stock_in_reference', $reference)->firstOrFail();
        $this->assertSame($reference, $transaction->fresh()->stock_in_reference);
        $this->getJson('/api/inventory/items/'.$id)->assertOk()
            ->assertJsonFragment(['stock_in_reference' => $reference]);
        $transaction->stock_in_reference = 'REPLACED-REF';
        try {
            $transaction->save();
            $this->fail('Receipt reference was changed');
        } catch (\LogicException $error) {
            $this->assertSame($reference, $transaction->fresh()->stock_in_reference);
        }
    }

    public function test_receipt_collision_retries_the_insert_without_double_incrementing_stock(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000093'));
        $id = $this->createInventoryItem('Dog Collar', 1, 50);
        DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop']);
        $this->recordInventoryTransaction($id, 'stock_in', 1, ['stock_in_reference' => 'DOGCOL-AAAAAAAAAA']);
        $service = \Mockery::mock(InventoryStockMovementService::class, [app(\App\Services\InventoryBatchBalanceService::class)])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('generateReceiptReference')->with('Dog Collar')->twice()->andReturn('DOGCOL-AAAAAAAAAA', 'DOGCOL-BBBBBBBBBB');
        $this->app->instance(InventoryStockMovementService::class, $service);
        DB::enableQueryLog();
        $this->postJson('/api/inventory/stock-in', ['items' => [['item_id' => $id, 'quantity' => 3, 'reason' => 'purchase']]])
            ->assertCreated()->assertJsonPath('data.0.stock_in_reference', 'DOGCOL-BBBBBBBBBB')->assertJsonPath('data.0.quantity_on_hand', '4.00');
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(0, $queries->filter(fn ($query) => str_starts_with(strtolower($query['query']), 'select') && str_contains($query['query'], 'stock_in_reference')));
        $this->assertDatabaseCount('inventory_transactions', 2);
    }

    public function test_receipt_retry_exhaustion_rolls_back_all_items_and_the_unique_index_rejects_duplicates(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000094'));
        $id = $this->createInventoryItem('Collar', 1, 50);
        DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop']);
        $this->recordInventoryTransaction($id, 'stock_in', 1, ['stock_in_reference' => 'COLLAR-AAAAAAAAAA']);
        $service = \Mockery::mock(InventoryStockMovementService::class, [app(\App\Services\InventoryBatchBalanceService::class)])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('generateReceiptReference')->times(6)->andReturn('COLLAR-BBBBBBBBBB', ...array_fill(0, 5, 'COLLAR-AAAAAAAAAA'));
        $this->app->instance(InventoryStockMovementService::class, $service);
        $entry = ['item_id' => $id, 'quantity' => 2, 'reason' => 'purchase'];
        $this->postJson('/api/inventory/stock-in', ['items' => [$entry, $entry]])->assertStatus(503);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseHas('inventory_items', ['item_id' => $id, 'quantity_on_hand' => 1]);
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->recordInventoryTransaction($id, 'stock_in', 1, ['stock_in_reference' => 'COLLAR-AAAAAAAAAA']);
    }

    public function test_simultaneous_stock_in_requests_preserve_exact_stock_and_unique_references(): void
    {
        $actor = $this->createUser('admin', '09170000095');
        $id = $this->createInventoryItem('Concurrent Collar', 0, 50);
        DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop']);
        $directory = sys_get_temp_dir().'/bethlehem-receipts-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700));
        $database = $directory.'/receipts.sqlite';
        $processes = [];
        try {
            DB::statement('VACUUM INTO '.DB::getPdo()->quote($database));
            $worker = <<<'PHP'
                require 'tests/bootstrap.php';
                $database = $argv[1];
                $directory = realpath(dirname($database));
                if (!$directory || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
                    || !preg_match('/\Abethlehem-receipts-[a-f0-9]{12}\z/', basename($directory))
                    || basename($database) !== 'receipts.sqlite' || !is_file($database)) {
                    throw new RuntimeException('An owned disposable receipt database is required.');
                }
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
                    'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000,
                    'database.connections.sqlite.transaction_mode' => 'IMMEDIATE']);
                Illuminate\Support\Facades\DB::purge('sqlite');
                $connection = Illuminate\Support\Facades\DB::connection();
                if (realpath($connection->selectOne('PRAGMA database_list')->file) !== realpath($database)) {
                    throw new RuntimeException('Unexpected receipt database.');
                }
                Laravel\Sanctum\Sanctum::actingAs(App\Models\User::findOrFail((int) $argv[2]));
                $role = $argv[4];
                $wait = function ($file) {
                    $deadline = microtime(true) + 5;
                    while (!is_file($file) && microtime(true) < $deadline) { clearstatcache(); usleep(1000); }
                    if (!is_file($file)) throw new RuntimeException('Receipt barrier timed out.');
                };
                $contended = false;
                $connection->beforeStartingTransaction(function () use ($directory, $database, $role, $wait, &$contended) {
                    if ($role === 'second') {
                        $wait($directory.'/locked');
                        $probe = new PDO('sqlite:'.$database);
                        $probe->exec('PRAGMA busy_timeout=0');
                        try {
                            $probe->exec('BEGIN IMMEDIATE');
                            $probe->exec('ROLLBACK');
                            throw new RuntimeException('The first receipt must still own the SQLite writer lock.');
                        } catch (PDOException $error) {
                            if (($error->errorInfo[1] ?? null) !== 5) throw $error;
                            $contended = true;
                        }
                        file_put_contents($directory.'/attempted', '1');
                    }
                });
                $connection->listen(function ($query) use ($directory, $role, $wait) {
                    if ($role === 'first' && str_starts_with(strtolower($query->sql), 'insert into "inventory_transactions"')) {
                        file_put_contents($directory.'/locked', '1');
                        $wait($directory.'/attempted');
                    }
                });
                $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
                $request = Illuminate\Http\Request::create('/api/inventory/stock-in', 'POST', [], [], [],
                    ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
                    json_encode(['items' => [['item_id' => (int) $argv[3], 'quantity' => 2, 'reason' => 'purchase']]]));
                $response = $kernel->handle($request);
                echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'pid' => getmypid(),
                    'php' => PHP_VERSION, 'binary' => PHP_BINARY, 'contended' => $contended, 'transaction_mode' => $connection->getConfig('transaction_mode')]);
                PHP;
            foreach (['first', 'second'] as $role) {
                $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, (string) $actor->user_id, (string) $id, $role], base_path(), [
                    'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => base_path('storage/framework/testing-config-unused.php'),
                    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array',
                    'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                ]);
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
            $this->assertTrue($results[1]['contended'], 'The second request must observe the first receipt writer lock.');
            foreach ($results as $result) {
                $this->assertSame(PHP_VERSION, $result['php']);
                $this->assertSame(PHP_BINARY, $result['binary']);
                $this->assertSame('IMMEDIATE', $result['transaction_mode']);
                $this->assertSame(201, $result['status'], json_encode($result['body']));
            }
            $this->assertNotSame($results[0]['body']['data'][0]['stock_in_reference'], $results[1]['body']['data'][0]['stock_in_reference']);
            $pdo = new \PDO('sqlite:'.$database);
            $this->assertEquals(4, $pdo->query('SELECT quantity_on_hand FROM inventory_items WHERE item_id = '.$id)->fetchColumn());
            $this->assertEquals(2, $pdo->query('SELECT COUNT(DISTINCT stock_in_reference) FROM inventory_transactions')->fetchColumn());
            $rows = $pdo->query('SELECT * FROM inventory_transactions ORDER BY transaction_id')->fetchAll(\PDO::FETCH_ASSOC);
            $this->assertCount(2, $rows, 'Exactly one ledger receipt is recorded for each successful request.');
            foreach ($rows as $index => $row) {
                $this->assertSame($id, (int) $row['item_id']);
                $this->assertSame($actor->user_id, (int) $row['performed_by']);
                $this->assertSame('stock_in', $row['type']);
                $this->assertSame('purchase', $row['reason']);
                $this->assertSame('manual', $row['reference_type']);
                $this->assertEquals(2, $row['quantity']);
                $this->assertEquals(50, $row['unit_cost_at_time']);
                $this->assertSame($row['stock_in_reference'], $results[$index]['body']['data'][0]['stock_in_reference']);
                $this->assertSame((int) $row['transaction_id'], $results[$index]['body']['data'][0]['transaction_id']);
            }
            $pdo = null;
        } finally {
            foreach ($processes as $process) if ($process->isRunning()) $process->stop();
            foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }

    public function test_details_preserves_non_expiring_categories_and_deactivated_products(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000032'));
        foreach (['pet_shop', 'miscellaneous'] as $category) {
            $itemId = $this->createInventoryItem("Non-expiring {$category}", 4, 50);
            DB::table('inventory_items')->where('item_id', $itemId)->update(['category' => $category]);
            $this->getJson("/api/inventory/items/{$itemId}")->assertOk()
                ->assertJsonPath('data.tracks_expiry', false)
                ->assertJsonPath('data.saleable_quantity', '4.00')
                ->assertJsonCount(0, 'data.current_batches');
        }
        $itemId = $this->createInventoryItem('Deactivated product', 0, 50);
        DB::table('inventory_items')->where('item_id', $itemId)->update(['is_active' => false]);
        $this->getJson("/api/inventory/items/{$itemId}")->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson("/api/inventory/items/{$itemId}/reactivate")->assertOk();
        $this->getJson("/api/inventory/items/{$itemId}")->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_legacy_receipts_without_expiry_are_not_marked_valid_in_details(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000033'));
        $itemId = $this->createInventoryItem('Legacy medicine', 4, 50);
        $this->recordInventoryTransaction($itemId, 'stock_in', 4);
        $this->getJson("/api/inventory/items/{$itemId}")->assertOk()
            ->assertJsonPath('data.saleable_quantity', 0)
            ->assertJsonPath('data.expiry_unknown_quantity', 4)
            ->assertJsonPath('data.current_batches.0.expiry_status', 'Expiry unknown');
    }

    public function test_retail_suggestions_are_bounded_eligible_and_rank_recent_successful_sales(): void
    {
        Sanctum::actingAs($actor = $this->createUser('staff', '09170000049'));
        $ids = [];
        foreach (range(1, 15) as $number) {
            $id = $this->createInventoryItem(sprintf('Suggest %02d', $number), 3, 50);
            DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop']);
            $ids[] = $id;
        }
        DB::table('inventory_items')->where('item_id', $ids[0])->update(['is_active' => false]);
        DB::table('inventory_items')->where('item_id', $ids[1])->update(['quantity_on_hand' => 0]);
        $this->recordInventoryTransaction($ids[14], 'stock_out', 1, ['reason' => 'sold']);
        foreach (['pos', 'grooming'] as $context) {
            $data = $this->getJson('/api/inventory/search?suggestions=1&sale_context='.$context)
                ->assertOk()->assertJsonCount(12, 'data')->json('data');
            $this->assertSame($ids[14], $data[0]['item_id']);
            $this->assertNotContains($ids[0], array_column($data, 'item_id'));
            $this->assertNotContains($ids[1], array_column($data, 'item_id'));
        }
        $this->getJson('/api/inventory/search?q=S&sale_context=pos')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pos_search_discovers_unavailable_retail_while_barcode_keeps_sale_guards(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000040'));
        $expected = [];
        foreach (['medicine', 'vaccine', 'food', 'grooming_supply', 'pet_shop', 'miscellaneous'] as $index => $category) {
            $id = $this->createInventoryItem('Retail '.$category, 0, 50);
            DB::table('inventory_items')->where('item_id', $id)->update(['category' => $category, 'barcode' => '100'.$index]);
            $response = $this->getJson('/api/inventory/barcode/100'.$index.'?sale_context=pos&include_inactive=1');
            if (in_array($category, ['medicine', 'vaccine'], true)) {
                $response->assertNotFound();
            } else {
                $response->assertOk();
                $this->assertEquals(0, $response->json('data.saleable_quantity'));
                $expected[] = $id;
            }
        }
        foreach ([['is_active' => false], ['selling_price' => null], ['selling_price' => 0]] as $changes) {
            $id = $this->createInventoryItem('Retail excluded', 1, 50);
            DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop', ...$changes]);
            $expected[] = $id;
        }
        $result = $this->getJson('/api/inventory/search?q=Retail&sale_context=pos&include_inactive=1')
            ->assertOk()->assertJsonCount(7, 'data');
        $this->assertEqualsCanonicalizing($expected, array_column($result->json('data'), 'item_id'));
    }

    public function test_pos_backend_rejects_medical_inactive_and_invalid_price_even_with_direct_submission(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000041'));
        foreach ([['category' => 'medicine'], ['category' => 'vaccine'], ['is_active' => false], ['selling_price' => null], ['selling_price' => 0]] as $changes) {
            $id = $this->createInventoryItem('Restricted Product', 3, 50);
            DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop', ...$changes]);
            $this->postJson('/api/pos/transactions', ['items' => [['item_id' => $id, 'quantity' => 1]], 'amount_tendered' => 100])
                ->assertUnprocessable();
            $this->assertDatabaseHas('inventory_items', ['item_id' => $id, 'quantity_on_hand' => 3]);
        }
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseCount('pos_transaction_items', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_pos_rejects_decimal_quantities_at_validation_and_checkout_without_rounding(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000045'));
        $id = $this->createInventoryItem('Whole Retail Product', 10, 50);
        DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop']);
        foreach ([1.02, 1.5, 2.75, '1.02', '2.75'] as $quantity) {
            $payload = ['items' => [['item_id' => $id, 'quantity' => $quantity]], 'amount_tendered' => 500];
            foreach (['/api/pos/validate-cart', '/api/pos/transactions'] as $endpoint) {
                $response = $this->postJson($endpoint, $payload)->assertUnprocessable()
                    ->assertJsonValidationErrors(['items.0.quantity']);
                $this->assertSame('Quantity must be a whole number.', $response->json('errors')['items.0.quantity'][0]);
            }
        }
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseCount('pos_transaction_items', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseHas('inventory_items', ['item_id' => $id, 'quantity_on_hand' => 10]);
    }

    public function test_pos_preflight_returns_current_retail_stock_prices_and_availability_without_writes(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000046'));
        $food = $this->createInventoryItem('Batch Retail Food', 4, 125.50);
        $this->recordInventoryTransaction($food, 'stock_in', 2, ['expiry_date' => now()->addYear()->toDateString()]);
        $this->recordInventoryTransaction($food, 'stock_in', 2, ['expiry_date' => now()->subDay()->toDateString()]);
        $inactive = $this->createInventoryItem('Inactive Product', 3, 50);
        DB::table('inventory_items')->where('item_id', $inactive)->update(['category' => 'pet_shop', 'is_active' => false]);
        $response = $this->postJson('/api/pos/validate-cart', ['items' => [
            ['item_id' => $food, 'quantity' => 3], ['item_id' => $inactive, 'quantity' => 1], ['item_id' => 999, 'quantity' => 1],
        ]])->assertOk()->assertJsonCount(3, 'data');
        $response->assertJsonPath('data.0.item_id', $food)->assertJsonPath('data.0.saleable_quantity', 2)
            ->assertJsonPath('data.0.selling_price', '125.50')->assertJsonPath('data.1.is_active', false)
            ->assertJsonPath('data.2.is_active', false)->assertJsonPath('data.2.saleable_quantity', 0);
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseCount('pos_transaction_items', 0);
        $this->assertDatabaseCount('inventory_transactions', 2);
        $this->assertDatabaseHas('inventory_items', ['item_id' => $food, 'quantity_on_hand' => 4]);
    }

    public function test_pos_rechecks_reviewed_prices_stock_and_availability_inside_checkout(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000047'));
        $id = $this->createInventoryItem('Concurrent Retail Product', 4, 250);
        DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop']);
        $payload = ['items' => [['item_id' => $id, 'quantity' => 1, 'expected_price' => 250, 'expected_stock' => 4]], 'amount_tendered' => 1000];
        foreach ([['selling_price' => 275], ['quantity_on_hand' => 3], ['selling_price' => 275, 'quantity_on_hand' => 3], ['is_active' => false]] as $changes) {
            $this->postJson('/api/pos/validate-cart', ['items' => [['item_id' => $id, 'quantity' => 1]]])->assertOk();
            DB::table('inventory_items')->where('item_id', $id)->update($changes);
            $this->postJson('/api/pos/transactions', $payload)->assertUnprocessable()->assertJsonPath('code', 'POS_CART_CHANGED')
                ->assertJsonPath('data.0.item_name', 'Concurrent Retail Product');
            $this->assertDatabaseCount('pos_transactions', 0);
            $this->assertDatabaseCount('pos_transaction_items', 0);
            $this->assertDatabaseCount('inventory_transactions', 0);
            DB::table('inventory_items')->where('item_id', $id)->update(['selling_price' => 250, 'quantity_on_hand' => 4, 'is_active' => true]);
        }
        $this->postJson('/api/pos/transactions', $payload)->assertCreated()->assertJsonPath('data.total_amount', '250.00')
            ->assertJsonPath('data.change_amount', '750.00')->assertJsonPath('data.items.0.quantity', '1.00');
        $this->assertDatabaseCount('pos_transactions', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseHas('inventory_items', ['item_id' => $id, 'quantity_on_hand' => 3]);
    }

    public function test_pos_cash_uses_grooming_payment_limits_and_validation_without_partial_writes(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000043'));
        $id = $this->createInventoryItem('Retail Cash Test', 10, 1600);
        DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop']);
        $payload = ['items' => [['item_id' => $id, 'quantity' => 1]]];
        foreach ([null, '', -1, 1599.99, 1600.001, '1600.000', 3000.01, str_repeat('9', 400)] as $cash) {
            $this->postJson('/api/pos/transactions', [...$payload, 'amount_tendered' => $cash])->assertUnprocessable();
            $this->assertDatabaseCount('pos_transactions', 0);
            $this->assertDatabaseCount('pos_transaction_items', 0);
            $this->assertDatabaseCount('inventory_transactions', 0);
            $this->assertDatabaseHas('inventory_items', ['item_id' => $id, 'quantity_on_hand' => 10]);
        }
        $this->postJson('/api/pos/transactions', $payload)->assertUnprocessable()
            ->assertJsonPath('errors.amount_tendered.0', 'Amount paid is required.');
        $this->postJson('/api/pos/transactions', [...$payload, 'amount_tendered' => 3000.01, 'total_amount' => 9000])
            ->assertUnprocessable()->assertJsonPath('errors.amount_tendered.0', 'Amount paid cannot exceed ₱3,000.00.');
        $this->postJson('/api/pos/transactions', [...$payload, 'amount_tendered' => 1600])
            ->assertCreated()->assertJsonPath('data.change_amount', '0.00');
        $this->postJson('/api/pos/transactions', [...$payload, 'amount_tendered' => 3000])
            ->assertCreated()->assertJsonPath('data.change_amount', '1400.00');
        $this->assertDatabaseCount('pos_transactions', 2);
        $this->assertDatabaseHas('inventory_items', ['item_id' => $id, 'quantity_on_hand' => 8]);
    }

    public function test_pos_consolidates_lines_tracks_the_authenticated_actor_and_preserves_invoice_snapshot(): void
    {
        $actor = $this->createUser('staff', '09170000042');
        Sanctum::actingAs($actor);
        $id = $this->createInventoryItem('Original Retail Name', 5, 125.50);
        DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop']);
        $sale = $this->postJson('/api/pos/transactions', [
            'items' => [['item_id' => $id, 'quantity' => 1], ['item_id' => $id, 'quantity' => 2]],
            'amount_tendered' => 500, 'cashier_id' => 999, 'customer_id' => 999,
        ])->assertCreated()->assertJsonPath('data.reference', 'POS-000001')
            ->assertJsonPath('data.cashier_id', $actor->user_id)->assertJsonPath('data.cashier_name', 'Staff Inventory Tester')
            ->assertJsonPath('data.total_amount', '376.50')->assertJsonPath('data.change_amount', '123.50')
            ->assertJsonCount(1, 'data.items');
        $this->assertDatabaseCount('pos_transactions', 1);
        $this->assertDatabaseCount('pos_transaction_items', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('inventory_items', ['item_id' => $id, 'quantity_on_hand' => 2]);
        $this->assertDatabaseHas('inventory_transactions', ['item_id' => $id, 'type' => 'stock_out', 'reason' => 'sold',
            'quantity' => 3, 'reference_type' => 'pos', 'reference_id' => 1, 'performed_by' => $actor->user_id]);
        $this->getJson('/api/inventory/transactions')->assertOk()->assertJsonPath('data.0.source_label', 'POS-000001');
        DB::table('inventory_items')->where('item_id', $id)->update(['item_name' => 'Renamed Retail Product', 'selling_price' => 900, 'is_active' => false]);
        $this->getJson('/api/pos/transactions/1')->assertOk()
            ->assertJsonPath('data.items.0.item_name', 'Original Retail Name')
            ->assertJsonPath('data.items.0.price_at_sale', '125.50')->assertJsonPath('data.items.0.subtotal', '376.50');
    }

    public function test_pos_rejects_stale_quotes_insufficient_stock_and_invalid_quantities_without_writes(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000043'));
        $id = $this->createInventoryItem('Changed Retail Product', 2, 50);
        DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop']);
        foreach ([['quantity' => 3], ['quantity' => 1, 'expected_price' => 40], ['quantity' => 0], ['quantity' => -1], ['quantity' => 1.001]] as $line) {
            $this->postJson('/api/pos/transactions', ['items' => [['item_id' => $id, ...$line]], 'amount_tendered' => 500])
                ->assertUnprocessable();
        }
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseCount('pos_transaction_items', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseHas('inventory_items', ['item_id' => $id, 'quantity_on_hand' => 2]);
    }

    public function test_pos_rolls_back_transaction_lines_and_all_stock_movements_after_a_late_failure(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000044'));
        $lines = [];
        foreach (['Shampoo', 'Collar'] as $name) {
            $id = $this->createInventoryItem($name, 3, 50);
            DB::table('inventory_items')->where('item_id', $id)->update(['category' => 'pet_shop']);
            $lines[] = ['item_id' => $id, 'quantity' => 2];
        }
        $movements = app(InventoryStockMovementService::class);
        $this->mock(InventoryStockMovementService::class)->shouldReceive('remove')->once()
            ->andReturnUsing(function ($actor, $entries) use ($movements) {
                $movements->remove($actor, $entries);
                abort(422, 'Simulated failure after inventory deductions.');
            });
        $this->postJson('/api/pos/transactions', ['items' => $lines, 'amount_tendered' => 200])->assertUnprocessable();
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseCount('pos_transaction_items', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        foreach ($lines as $line) {
            $this->assertDatabaseHas('inventory_items', ['item_id' => $line['item_id'], 'quantity_on_hand' => 3]);
        }
    }

    private function createUser(string $role, string $phone): User
    {
        return User::query()->create([
            'first_name' => ucfirst($role),
            'last_name' => 'Inventory Tester',
            'email' => "{$role}.inventory@example.test",
            'phone' => $phone,
            'password_hash' => bcrypt('password'),
            'role' => $role,
            'customer_tier' => 'new',
            'is_active' => true,
            'is_archived' => false,
            'email_verified_at' => now(),
        ]);
    }

    private function createInventoryItem(string $name, float $quantity, ?float $sellingPrice): int
    {
        return (int) DB::table('inventory_items')->insertGetId([
            'item_name' => $name,
            'medicine_type' => 'general_medication', 'dose_unit' => 'tablet', 'pricing_method' => 'flat',
            'category' => 'medicine',
            'unit' => 'piece',
            'unit_cost' => 50,
            'selling_price' => $sellingPrice,
            'quantity_on_hand' => $quantity,
            'reorder_level' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'item_id');
    }

    private function recordInventoryTransaction(
        int $itemId,
        string $type,
        float $quantity,
        array $overrides = [],
    ): int {
        return (int) DB::table('inventory_transactions')->insertGetId([
            'item_id' => $itemId,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $type === 'stock_in' ? 'purchase' : 'adjustment',
            'reference_type' => 'manual',
            'created_at' => now(),
            ...$overrides,
        ], 'transaction_id');
    }
}
