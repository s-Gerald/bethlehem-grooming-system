<?php

use App\Services\ClinicPerformanceReport;
use App\Services\GroomingPerformanceReport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || DB::getDriverName() !== 'mysql'
    || ! in_array(DB::connection()->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('This read-only smoke check requires the local MySQL environment.');
}
$server = DB::selectOne('SELECT VERSION() AS version, @@SESSION.sql_mode AS modes');
if (! str_contains($server->modes, 'ONLY_FULL_GROUP_BY')) throw new RuntimeException('Strict grouping must be enabled.');
echo json_encode($server).PHP_EOL;
DB::statement('SET TRANSACTION READ ONLY');
DB::beginTransaction();
try {
    foreach (['grooming' => GroomingPerformanceReport::class, 'clinic' => ClinicPerformanceReport::class] as $section => $service) {
        foreach (['today', 'last_7_days', 'this_month', 'last_month', 'custom'] as $period) {
            $report = app($service)->build(['period' => $period, 'start' => '2026-01-01', 'end' => '2026-12-31']);
            if (abs(array_sum(array_column($report['performance'], 'collections')) - $report['overview']['collections']) > .01
                || array_sum(array_column($report['performance'], 'visits')) !== $report['overview']['completedVisits']) {
                throw new RuntimeException("$section $period: chart and overview disagree.");
            }
            echo json_encode(['section' => $section, 'period' => $period, 'overview' => $report['overview'],
                'services' => count($report['services']), 'outcomes' => $report['outcomes']]).PHP_EOL;
        }
    }
    echo 'Clinic recorded states: '.json_encode(DB::table('clinic_appointments')->selectRaw('status, COUNT(*) AS cases')
        ->groupBy('status')->pluck('cases', 'status')).PHP_EOL;
} finally {
    DB::rollBack();
}
