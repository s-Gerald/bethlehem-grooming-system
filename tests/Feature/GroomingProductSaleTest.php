<?php

namespace Tests\Feature;

use App\Models\GroomingPaymentProduct;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\GroomingProductSaleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GroomingProductSaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->increments('item_id');
            $table->string('item_name');
            $table->string('category');
            $table->string('unit');
            $table->decimal('unit_cost', 8, 2)->default(0);
            $table->decimal('selling_price', 8, 2)->nullable();
            $table->decimal('quantity_on_hand', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->increments('transaction_id');
            $table->unsignedInteger('item_id');
            $table->string('type');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_cost_at_time', 8, 2)->nullable();
            $table->decimal('selling_price_at_time', 8, 2)->nullable();
            $table->string('reason');
            $table->string('reference_type');
            $table->unsignedInteger('reference_id')->nullable();
            $table->unsignedInteger('performed_by')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('batch_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('booking_id');
            $table->decimal('total_amount', 10, 2);
            $table->timestamps();
        });
        Schema::create('grooming_payment_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedInteger('item_id');
            $table->string('item_name');
            $table->unsignedInteger('quantity');
            $table->decimal('price_at_sale', 8, 2);
            $table->decimal('subtotal', 10, 2);
        });
    }

    protected function tearDown(): void
    {
        foreach (['grooming_payment_products', 'payments', 'inventory_transactions', 'inventory_items'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_products_are_deducted_and_linked_only_when_the_payment_is_recorded(): void
    {
        $item = $this->item('Pet Shampoo', 'grooming_supply', 3, '250.00');
        InventoryTransaction::create([
            'item_id' => $item->item_id, 'type' => 'stock_in', 'quantity' => 3,
            'reason' => 'purchase', 'reference_type' => 'manual',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $sale = app(GroomingProductSaleService::class);
        [$lines, $cents] = $sale->prepare([['item_id' => $item->item_id, 'quantity' => 2]]);
        $this->assertSame(50000, $cents);
        $this->assertSame('3.00', $item->fresh()->quantity_on_hand);
        $this->assertSame(0, GroomingPaymentProduct::count());

        $actor = new User;
        $actor->user_id = 7;
        DB::transaction(function () use ($sale, $lines, $actor) {
            $payment = Payment::create(['booking_id' => 1025, 'total_amount' => '1200.00']);
            $sale->record($payment, $actor, $lines);
        });

        $this->assertSame('1.00', $item->fresh()->quantity_on_hand);
        $this->assertSame('500.00', GroomingPaymentProduct::first()->subtotal);
        $item->update(['selling_price' => '300.00']);
        $this->assertSame('250.00', GroomingPaymentProduct::first()->price_at_sale);
        $this->assertSame('500.00', GroomingPaymentProduct::first()->subtotal);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $item->item_id, 'type' => 'stock_out', 'quantity' => 2,
            'reason' => 'sold', 'reference_type' => 'grooming', 'reference_id' => 1025,
        ]);
    }

    public function test_lost_stock_and_excluded_categories_stop_the_sale_without_moving_stock(): void
    {
        $item = $this->item('Dog Treats', 'pet_shop', 1, '80.00');
        $medicine = $this->item('Medicine', 'medicine', 4, '120.00');
        $sale = app(GroomingProductSaleService::class);

        foreach ([
            [['item_id' => $item->item_id, 'quantity' => 2]],
            [['item_id' => $medicine->item_id, 'quantity' => 1]],
        ] as $request) {
            try {
                $sale->prepare($request);
                $this->fail('The product should be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('products', $exception->errors());
            }
        }

        $this->assertSame('1.00', $item->fresh()->quantity_on_hand);
        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_stock_is_checked_again_after_a_product_was_available(): void
    {
        $item = $this->item('Dog Treats', 'pet_shop', 1, '80.00');
        $sale = app(GroomingProductSaleService::class);
        $sale->prepare([['item_id' => $item->item_id, 'quantity' => 1]]);
        $item->update(['quantity_on_hand' => 0]);

        try {
            $sale->prepare([['item_id' => $item->item_id, 'quantity' => 1]]);
            $this->fail('Stock lost after searching must stop payment confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('products', $exception->errors());
        }

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, GroomingPaymentProduct::count());
        $this->assertSame(0, InventoryTransaction::count());
    }

    public function test_expired_or_unknown_expiry_stock_cannot_be_added_to_a_payment(): void
    {
        $item = $this->item('Pet Shampoo', 'grooming_supply', 3, '250.00');
        InventoryTransaction::create([
            'item_id' => $item->item_id, 'type' => 'stock_in', 'quantity' => 2,
            'reason' => 'purchase', 'reference_type' => 'manual',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
        InventoryTransaction::create([
            'item_id' => $item->item_id, 'type' => 'stock_in', 'quantity' => 1,
            'reason' => 'purchase', 'reference_type' => 'manual',
        ]);

        $this->expectException(ValidationException::class);
        app(GroomingProductSaleService::class)->prepare([['item_id' => $item->item_id, 'quantity' => 1]]);
    }

    public function test_nonexpiring_pet_shop_products_can_be_sold_against_physical_stock(): void
    {
        $item = $this->item('Dog Treats', 'pet_shop', 2, '80.00');
        $sale = app(GroomingProductSaleService::class);
        [$lines, $cents] = $sale->prepare([['item_id' => $item->item_id, 'quantity' => 1]]);
        $this->assertSame(8000, $cents);

        $actor = new User;
        $actor->user_id = 7;
        DB::transaction(function () use ($sale, $lines, $actor) {
            $sale->record(Payment::create(['booking_id' => 100, 'total_amount' => '780.00']), $actor, $lines);
        });

        $this->assertSame('1.00', $item->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $item->item_id, 'reference_type' => 'grooming', 'reason' => 'sold',
        ]);
    }

    private function item(string $name, string $category, int $stock, string $price): InventoryItem
    {
        return InventoryItem::create([
            'item_name' => $name, 'category' => $category, 'unit' => 'bottle',
            'quantity_on_hand' => $stock, 'selling_price' => $price,
        ]);
    }
}
