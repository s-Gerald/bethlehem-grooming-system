<?php

use App\Http\Controllers\GroomingPricingController;
use App\Models\Service;
use App\Services\GroomingServicePriceResolver;
use Illuminate\Contracts\Console\Kernel;

// Feed JS regression tests through the same normalizer used by the live endpoint.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$controller = new GroomingPricingController;
$normalizer = new ReflectionMethod($controller, 'normalize');
$resolver = new GroomingServicePriceResolver;
$catalogue = [];
foreach (array_keys(config('grooming_services.services')) as $slug) {
    $name = match ($slug) {
        'cat_full_grooming' => 'Full Grooming', 'bath_and_go' => 'Bath and Go!',
        default => ucwords(str_replace('_', ' ', $slug)),
    };
    $service = new Service(['slug' => $slug, 'service_name' => $name, 'base_price' => 0]);
    $defaults = collect(json_decode(file_get_contents(database_path('data/grooming-catalog.json')), true))->firstWhere('id', $slug);
    $service->pet_type = $defaults['petType'] ?? 'all';
    $service->description_items = $defaults['descriptionItems'];
    $entry = $normalizer->invoke($controller, $service, $resolver);
    $entry['includedAlaCarteServiceIds'] = $defaults['includedAlaCarteServiceIds'] ?? [];
    $entry['serviceId'] = count($catalogue) + 1;
    $catalogue[] = $entry;
}
echo json_encode(['data' => $catalogue, 'priceLimits' => config('grooming_services.price_limits'), 'groomingPreferences' => config('grooming_services.preferences')], JSON_THROW_ON_ERROR);
