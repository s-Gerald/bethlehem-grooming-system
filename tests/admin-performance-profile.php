<?php
// Local-only profiler. Existing data only; all controller side effects roll back.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('local') || ! in_array(config('database.connections.'.config('database.default').'.host'), ['127.0.0.1', 'localhost', null], true)) {
    throw new RuntimeException('Only a local application/database may be profiled.');
}
$admin = App\Models\User::where('role', 'admin')->firstOrFail();
$request = Illuminate\Http\Request::create('/api/admin/customers?status=active');
$request->setUserResolver(fn () => $admin);
$app->instance('request', $request);
Illuminate\Support\Facades\Auth::setUser($admin);
$request->setUserResolver(fn () => $admin);
$db = Illuminate\Support\Facades\DB::connection();
$db->beginTransaction();
try {
    $booking = new App\Http\Controllers\AdminBookingController;
    $clinic = new App\Http\Controllers\AdminClinicController;
    $reads = [
        'customers' => fn () => (new App\Http\Controllers\CustomerController)->index($request),
        'dashboard-summary' => fn () => $booking->dashboardSummary(),
        'dashboard-activity' => fn () => $booking->dashboardActivity(),
        'grooming-board' => fn () => $booking->index(Illuminate\Http\Request::create('/api/admin/bookings?date='.now()->toDateString().'&include_future=1&view=board')),
        'grooming-activity' => fn () => $booking->activityIndicator(),
        'clinic-active' => fn () => $clinic->activeCases(),
        'clinic-records' => fn () => $clinic->records(Illuminate\Http\Request::create('/api/admin/clinic-records?page=1')),
    ];
    foreach ($reads as $name => $read) {
        $db->flushQueryLog(); $db->enableQueryLog(); $start = hrtime(true);
        $response = $read();
        echo json_encode(['endpoint' => $name, 'queries' => count($db->getQueryLog()),
            'sql_ms' => round(array_sum(array_column($db->getQueryLog(), 'time')), 2),
            'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 2), 'bytes' => strlen($response->getContent())]).PHP_EOL;
        $db->disableQueryLog();
    }
} finally { $db->rollBack(); }
