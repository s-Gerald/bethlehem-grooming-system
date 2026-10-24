<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Services\GroomingServicePriceResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GroomingPricingController extends Controller
{
    public function index(Request $request, GroomingServicePriceResolver $resolver)
    {
        $billing = $request->boolean('include_inactive') && in_array($request->user()?->role, ['admin', 'staff'], true);
        return $this->catalogue($billing, $resolver);
    }

    public function managedIndex(Request $request, GroomingServicePriceResolver $resolver)
    {
        abort_unless($request->user()?->role === 'admin', 403);
        return $this->catalogue(true, $resolver);
    }

    public function staffIndex(Request $request, GroomingServicePriceResolver $resolver)
    {
        abort_unless($request->user()?->staffPermissions()['grooming_services_view'] ?? false, 403);
        return $this->catalogue(true, $resolver);
    }

    private function catalogue(bool $all, GroomingServicePriceResolver $resolver)
    {
        $services = Service::whereIn('kind', ['package', 'ala_carte'])->orderBy('service_id')->get();
        $ids = $services->pluck('slug', 'service_id')->all();
        $visible = $all ? $services : $services->where('is_active', true)->values();
        $catalogue = $visible->map(fn ($service) => $this->normalize($service, $resolver, $ids));
        $prices = $catalogue->mapWithKeys(fn ($service) => [$service['serviceId'] => [
            min(array_column($service['priceOptions'], 'minAmount')),
            max(array_map(fn ($option) => $option['maxAmount'] ?? $option['minAmount'], $service['priceOptions'])),
        ]]);
        $catalogue = $catalogue->sort(function ($a, $b) use ($prices) {
            if ($a['kind'] !== $b['kind']) return $a['kind'] === 'package' ? -1 : 1;
            return ($prices[$a['serviceId']] <=> $prices[$b['serviceId']]) ?: strcasecmp($a['name'], $b['name']);
        })->values();
        return response()->json(['data' => $catalogue,
            'priceLimits' => config('grooming_services.price_limits'),
            'estimateRules' => app(\App\Services\GroomingTimeEstimate::class)->rules($services)])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, GroomingServicePriceResolver $resolver)
    {
        return $this->saveService($request, new Service, $resolver);
    }

    public function save(Request $request, Service $service, GroomingServicePriceResolver $resolver)
    {
        return $this->saveService($request, $service, $resolver);
    }

    private function saveService(Request $request, Service $service, GroomingServicePriceResolver $resolver)
    {
        abort_unless($request->user()?->role === 'admin', 403);
        $created = ! $service->exists;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', 'in:package,ala_carte'],
            'petType' => ['required_if:kind,package', 'in:dog,cat,all'],
            'sizes' => ['required_if:kind,package', 'array'],
            'descriptionItems' => ['required_if:kind,package', 'array', 'max:20'],
            'descriptionItems.*' => ['required', 'string', 'max:300'],
            'includedServiceIds' => ['sometimes', 'array', 'max:100'],
            'includedServiceIds.*' => ['integer', 'distinct', \Illuminate\Validation\Rule::exists('services', 'service_id')->where('kind', 'ala_carte')],
            'isActive' => ['required', 'boolean'],
        ]);
        if (! $created && $service->kind !== $data['kind']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['kind' => 'Service type cannot be changed.']);
        }
        $service->kind = $data['kind'];
        $package = $service->kind === 'package';
        $sizes = $package ? array_values(array_intersect(['small', 'medium', 'large', 'extra_large'], array_keys($request->input('sizes', [])))) : [];
        if ($package && ! $sizes) {
            throw \Illuminate\Validation\ValidationException::withMessages(['sizes' => 'Enter size pricing.']);
        }
        $prices = $this->priceValues($request, $package, $sizes);
        DB::transaction(function () use ($service, $data, $created, $package, $prices) {
            if ($created) $service->slug = 'grooming_'.(string) \Illuminate\Support\Str::uuid();
            $service->fill($prices + [
                'service_name' => $data['name'], 'pet_type' => $package ? $data['petType'] : 'all',
                'description_items' => $package ? $data['descriptionItems'] : [],
                'description' => $package ? implode("\n", $data['descriptionItems']) : null,
                'included_ala_carte_ids' => $package ? ($data['includedServiceIds'] ?? []) : [],
                'is_active' => $data['isActive'],
            ]);
            // New services use the existing duration field for operational estimates.
            if ($created) $service->duration_minutes = $package ? 60 : 15;
            $service->save();
        });
        return response()->json(['data' => $this->normalize($service->fresh(), $resolver)], $created ? 201 : 200);
    }

    public function status(Request $request, Service $service, GroomingServicePriceResolver $resolver)
    {
        abort_unless($request->user()?->role === 'admin', 403);
        abort_unless(in_array($service->kind, ['package', 'ala_carte'], true), 404);
        $data = $request->validate(['isActive' => ['required', 'boolean']]);
        $service->update(['is_active' => $data['isActive']]);
        return response()->json(['data' => $this->normalize($service->fresh(), $resolver)]);
    }

    public function update(Request $request, Service $service, GroomingServicePriceResolver $resolver)
    {
        abort_unless($request->user()?->role === 'admin', 403);
        abort_unless(in_array($service->kind, ['package', 'ala_carte'], true), 404);
        $service->update($this->priceValues($request, $service->kind === 'package', $service->pricingSizes()));
        return response()->json(['data' => $this->normalize($service->fresh(), $resolver)]);
    }

    private function priceValues(Request $request, bool $package, array $sizes): array
    {
        $limits = config('grooming_services.price_limits.'.($package ? 'package' : 'ala_carte'));
        $money = ['bail', 'required', 'numeric', 'regex:/^\d+(?:\.\d{1,2})?$/D',
            'min:'.$limits['minimum'], 'max:'.$limits['maximum']];
        $rules = $package ? ['sizes' => ['required', 'array:'.implode(',', $sizes)]] : [
            'pricing_type' => 'required|in:fixed,range,starting_at',
            'amount' => $money,
        ];
        if ($package) {
            foreach ($sizes as $size) {
                $rules["sizes.$size"] = 'required|array:pricing_type,amount,maximum';
                $rules["sizes.$size.pricing_type"] = 'required|in:fixed,range,starting_at';
                $rules["sizes.$size.amount"] = $money;
                if ($request->input("sizes.$size.pricing_type") === 'range') {
                    $rules["sizes.$size.maximum"] = $money;
                }
            }
        } elseif ($request->input('pricing_type') === 'range') {
            $rules['maximum'] = $money;
        }
        $amountMessage = 'Enter a reasonable price amount.';
        $messages = [
            'sizes.required' => 'Enter a price.',
            'pricing_type.required' => 'Enter a price.',
            'sizes.array' => 'Only the supported package sizes may be updated.',
        ];
        foreach ($rules as $field => $fieldRules) {
            if (is_array($fieldRules) && in_array('numeric', $fieldRules, true)) {
                foreach (['required', 'numeric', 'regex', 'min', 'max'] as $rule) {
                    $messages[$field.'.'.$rule] = $amountMessage;
                }
            }
        }
        $validator = Validator::make($request->all(), $rules, $messages);
        $validator->after(function ($validator) use ($request, $package, $sizes) {
            foreach ($package ? $sizes : [''] as $size) {
                $prefix = $package ? "sizes.$size." : '';
                if ($request->input($prefix.'pricing_type') === 'range'
                    && ! $validator->errors()->has($prefix.'amount')
                    && ! $validator->errors()->has($prefix.'maximum')
                    && (float) $request->input($prefix.'maximum') <= (float) $request->input($prefix.'amount')) {
                    $validator->errors()->add($prefix.'maximum', 'Maximum price must be greater than minimum price.');
                }
            }
            if (! $package || $validator->errors()->isNotEmpty()) {
                return;
            }
            $previous = 0;
            foreach ($sizes as $size) {
                $amount = (float) $request->input("sizes.$size.amount");
                if ($amount < $previous) {
                    $validator->errors()->add("sizes.$size.amount", 'Price must not be lower than the preceding size.');
                }
                $previous = $amount;
            }
        });
        $data = $validator->validate();

        $values = ['base_price' => null, 'price_min' => null, 'price_max' => null,
            'is_starting_price' => false, 'starting_price_sizes' => [], 'range_price_maximums' => []];
        foreach (['small', 'medium', 'large', 'extra_large'] as $size) {
            $values['price_'.$size] = null;
        }
        if ($package) {
            foreach ($sizes as $size) {
                $values['price_'.$size] = $data['sizes'][$size]['amount'];
                if ($data['sizes'][$size]['pricing_type'] === 'starting_at') {
                    $values['starting_price_sizes'][] = $size;
                } elseif ($data['sizes'][$size]['pricing_type'] === 'range') {
                    $values['range_price_maximums'][$size] = $data['sizes'][$size]['maximum'];
                }
            }
            $values['base_price'] = $data['sizes']['medium']['amount'] ?? $data['sizes'][$sizes[0]]['amount'];
        } else {
            $values['base_price'] = $data['amount'];
            if ($data['pricing_type'] !== 'fixed') {
                $values['price_min'] = $data['amount'];
            }
            if ($data['pricing_type'] === 'range') {
                $values['price_max'] = $data['maximum'];
            }
            $values['is_starting_price'] = $data['pricing_type'] === 'starting_at';
        }
        return $values;
    }

    private function normalize(Service $service, GroomingServicePriceResolver $resolver, ?array $ids = null): array
    {
        $ids ??= $service->included_ala_carte_ids ? Service::query()->pluck('slug', 'service_id')->all() : [];
        $included = array_values(array_filter(array_map(fn ($id) => $ids[$id] ?? null, $service->included_ala_carte_ids ?? [])));
        return ['serviceId' => $service->service_id, 'id' => $service->slug,
            'name' => $service->service_name, 'kind' => $service->kind,
            'petType' => $service->pet_type ?? ($service->slug === 'cat_full_grooming' ? 'cat' : 'dog'),
            'petTypes' => $service->kind === 'ala_carte' ? ['cat', 'dog'] : null,
            'descriptionItems' => $service->description_items ?? [],
            'includedServiceIds' => $service->included_ala_carte_ids ?? [],
            'includedAlaCarteServiceIds' => $included,
            'allowsAlaCarteServices' => true, 'isActive' => $service->is_active,
            'displayOrder' => $service->display_order ?? 0,
            'priceOptions' => $this->priceOptions($service, $resolver),
            'defaultPriceOptions' => config("grooming_services.services.{$service->slug}")
                ? $this->priceOptions(new Service(['slug' => $service->slug]), $resolver) : []];
    }

    private function priceOptions(Service $service, GroomingServicePriceResolver $resolver): array
    {
        $sizes = $service->kind === 'package' ? $service->pricingSizes() : [''];
        $labels = ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large', 'extra_large' => 'Extra Large', '' => 'Standard'];
        return array_map(function ($size) use ($service, $resolver, $labels) {
            $bounds = $resolver->bookingPriceBounds($service, $size);
            $min = (float) ($bounds['min'] ?? $resolver->servicePrice($service, $size));
            $max = $bounds['min'] === null ? $min : ($bounds['max'] === null ? null : (float) $bounds['max']);
            return ['label' => $labels[$size], 'sizeKey' => $size,
                'pricingType' => $max === null ? 'starting_at' : ($min === $max ? 'fixed' : 'range'),
                'minAmount' => $min, 'maxAmount' => $max];
        }, $sizes);
    }
}
