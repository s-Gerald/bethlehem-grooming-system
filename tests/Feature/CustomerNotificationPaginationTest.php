<?php

namespace Tests\Feature;

use App\Http\Controllers\CustomerNotificationController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerNotificationPaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('type');
            $table->text('message');
            $table->boolean('is_read');
            $table->timestamp('created_at');
        });

        for ($number = 1; $number <= 35; $number++) {
            DB::table('customer_notifications')->insert([
                'user_id' => 1,
                'type' => 'reminder_24h',
                'message' => "Notification {$number}",
                'is_read' => $number % 2 === 0,
                'created_at' => sprintf('2026-09-15 10:%02d:00', $number),
            ]);
        }
        DB::table('customer_notifications')->insert([
            'user_id' => 2,
            'type' => 'reminder_24h',
            'message' => 'Another customer',
            'is_read' => false,
            'created_at' => '2026-09-15 11:00:00',
        ]);
    }

    public function test_client_full_page_can_load_older_notifications_and_filter_unread(): void
    {
        $controller = new CustomerNotificationController;
        $first = $controller->index($this->customerRequest(['sort' => 'recent']))->getData(true);
        $second = $controller->index($this->customerRequest(['sort' => 'recent', 'page' => 2]))->getData(true);
        $unread = $controller->index($this->customerRequest(['sort' => 'recent', 'status' => 'unread']))->getData(true);

        $this->assertCount(30, $first['notifications']);
        $this->assertTrue($first['has_more']);
        $this->assertCount(5, $second['notifications']);
        $this->assertFalse($second['has_more']);
        $this->assertSame('Notification 35', $first['notifications'][0]['message']);
        $this->assertSame('Notification 5', $second['notifications'][0]['message']);
        $this->assertSame(18, $first['unread_count']);
        $this->assertCount(18, $unread['notifications']);
        $this->assertTrue(collect($unread['notifications'])->every(fn ($item) => ! $item['is_read']));
    }

    public function test_mark_all_read_still_updates_notifications_beyond_first_page(): void
    {
        $controller = new CustomerNotificationController;
        $controller->markAllRead($this->customerRequest());

        $this->assertSame(0, DB::table('customer_notifications')->where('user_id', 1)->where('is_read', false)->count());
        $this->assertSame(1, DB::table('customer_notifications')->where('user_id', 2)->where('is_read', false)->count());
    }

    public function test_registration_notifications_use_queue_terminology_for_saved_messages(): void
    {
        foreach ([
            ['grooming_no_show', 'Your Grooming Online Registration was marked No Show.', 'Your Grooming Online Queue Registration was marked No Show.', 'Grooming Queue Registration — No Show'],
            ['grooming_pre_registration_expired', 'Your pre-registration expired.', 'Your Online Queue Registration expired.', 'Grooming Queue Registration expired'],
            ['reminder_24h', 'New booking BAC-TEST-1 received.', 'New Online Queue Registration BAC-TEST-1 received.', null],
            ['reminder_24h', 'Your Online Queue Registration is waiting.', 'Your Online Queue Registration is waiting.', null],
            ['reminder_24h', 'Your walk-in visit is waiting.', 'Your walk-in visit is waiting.', null],
        ] as [$type, $stored, $expected, $title]) {
            DB::table('customer_notifications')->where('user_id', 1)->update(['type' => $type, 'message' => $stored]);
            $item = (new CustomerNotificationController)->index($this->customerRequest())->getData(true)['notifications'][0];
            $this->assertSame($expected, $item['message']);
            $this->assertSame($expected, $item['display_message']);
            $this->assertSame($title, $item['title']);
        }
    }

    private function customerRequest(array $query = []): Request
    {
        $request = Request::create('/api/customer/notifications', 'GET', $query);
        $request->setUserResolver(fn () => (object) ['user_id' => 1]);

        return $request;
    }
}
