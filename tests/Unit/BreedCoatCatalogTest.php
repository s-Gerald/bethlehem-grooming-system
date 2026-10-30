<?php

namespace Tests\Unit;

use App\Rules\ValidBreedCoat;
use App\Models\Pet;
use App\Support\BreedCoatCatalog;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class BreedCoatCatalogTest extends TestCase
{
    public function test_existing_selection_preserves_legacy_profiles_but_cannot_bypass_changed_breed_validation(): void
    {
        $pet = new Pet(['species' => 'Dog', 'breed' => 'Golden Retriever', 'fur_type' => null]);
        $this->assertNull(ValidBreedCoat::validatedCoat(['pet_id' => 1, 'breed' => 'Golden Retriever'], $pet));
        $pet->fur_type = 'Wavy Coat';
        $this->assertSame('Wavy Coat', ValidBreedCoat::validatedCoat(['breed' => 'Shih Tzu'], $pet));
        $this->assertNull(ValidBreedCoat::validatedCoat(['breed' => 'Custom dog', 'fur_type' => null], $pet));
        $this->assertNull(ValidBreedCoat::validatedCoat(['pet_id' => 1, 'breed' => 'Beagle'], $pet));
    }

    public function test_named_breeds_allow_missing_null_or_empty_coats(): void
    {
        foreach ([['Dog', 'Golden Retriever'], ['Cat', 'Bengal'], ['dog', ' beagle '], ['cat', ' PERSIAN ']] as [$species, $breed]) {
            foreach ([[], ['fur_type' => null], ['fur_type' => '']] as $coat) {
                $pet = ['species' => $species, 'breed' => $breed, ...$coat];
                foreach ([false, true] as $nested) {
                    $validator = Validator::make($nested ? ['pets' => [$pet]] : $pet, [
                        $nested ? 'pets.*.fur_type' : 'fur_type' => ['nullable', 'string', new ValidBreedCoat],
                    ]);
                    $this->assertFalse($validator->fails(), "$species / $breed allows blank fur");
                }
            }
        }
    }

    public function test_walk_in_requests_validate_each_new_pet_and_allow_unchanged_existing_selection(): void
    {
        foreach ([new \App\Http\Requests\StoreWalkinRequest, new \App\Http\Requests\StoreClinicWalkinRequest] as $request) {
            $nested = $request instanceof \App\Http\Requests\StoreWalkinRequest;
            $key = $nested ? 'pets.*.fur_type' : 'fur_type';
            $rules = [$key => $request->rules()[$key]];
            foreach (['Dog' => 'Golden Retriever', 'Cat' => 'Bengal'] as $species => $breed) {
                $pet = ['species' => $species, 'breed' => $breed];
                $data = $nested ? ['pets' => [$pet, [...$pet, 'pet_id' => 101]]] : $pet;
                $validator = Validator::make($data, $rules);
                $this->assertFalse($validator->fails());
                if ($nested) {
                    $this->assertFalse($validator->errors()->has('pets.1.fur_type'));
                }
            }
        }
    }

    public function test_new_profile_without_fur_remains_optional_after_resolution(): void
    {
        $this->assertNull(ValidBreedCoat::validatedCoat(['pet_id' => 123, 'breed' => 'Golden Retriever', 'species' => 'Dog']));
    }

    public function test_custom_and_generic_breeds_remain_optional_but_supplied_coats_are_validated(): void
    {
        foreach ([['Dog', 'Custom dog'], ['Cat', 'Custom cat'], ['Dog', 'Mixed Breed / Aspin'], ['Cat', 'Mixed Breed / Puspin'], ['Dog', 'Unknown Breed'], ['Cat', 'Unknown Breed']] as [$species, $breed]) {
            $rules = ['fur_type' => ['nullable', 'string', new ValidBreedCoat]];
            $pet = ['species' => $species, 'breed' => $breed];
            $this->assertFalse(Validator::make($pet, $rules)->fails());
            $this->assertFalse(Validator::make([...$pet, 'fur_type' => null], $rules)->fails());
            $this->assertTrue(Validator::make([...$pet, 'fur_type' => 'Unsupported'], $rules)->fails());
        }
        $this->assertSame(['Unknown Fur Type'], BreedCoatCatalog::optionsFor('Custom dog', 'Dog'));
        $this->assertSame(['Unknown Fur Type'], BreedCoatCatalog::optionsFor('Persian', 'Dog'));
    }

    public function test_unknown_fur_is_last_and_valid_for_every_catalogue_breed_and_blank_or_custom_breeds(): void
    {
        foreach (BreedCoatCatalog::all() as $species => $breeds) {
            foreach ([...array_keys($breeds), '', 'Custom pet'] as $breed) {
                $options = BreedCoatCatalog::optionsFor($breed, $species);
                $this->assertSame('Unknown Fur Type', end($options));
                $this->assertFalse(Validator::make(['species' => $species, 'breed' => $breed, 'fur_type' => 'Unknown Fur Type'],
                    ['fur_type' => ['nullable', 'string', new ValidBreedCoat]])->fails());
            }
        }
    }

    public function test_every_catalogue_breed_has_direct_coat_options(): void
    {
        $catalogue = BreedCoatCatalog::all();

        $this->assertSame(['Dog', 'Cat'], array_keys($catalogue));

        foreach ($catalogue as $breeds) {
            foreach ($breeds as $breed => $options) {
                $this->assertNotEmpty($options, "{$breed} must have coat options.");
                $this->assertSame(array_values($options), array_values(array_unique($options)));

                foreach ($options as $option) {
                    $this->assertIsString($option);
                    $this->assertSame($option, trim($option));
                }
            }
        }
    }

    public function test_siberian_husky_exposes_only_direct_requested_coats(): void
    {
        $this->assertSame(
            ['Standard Coat', 'Wooly Coat', 'Unknown Fur Type'],
            BreedCoatCatalog::optionsFor('Siberian Husky', 'Dog'),
        );
        $this->assertNotContains(
            'Hairless',
            BreedCoatCatalog::optionsFor('Siberian Husky', 'Dog'),
        );
    }

    public function test_mixed_breed_labels_are_species_specific(): void
    {
        $this->assertNotEmpty(BreedCoatCatalog::optionsFor('Mixed Breed / Aspin', 'Dog'));
        $this->assertNotEmpty(BreedCoatCatalog::optionsFor('Mixed Breed / Puspin', 'Cat'));
    }

    public function test_impossible_breed_and_coat_combination_is_rejected(): void
    {
        $validator = Validator::make([
            'pets' => [[
                'species' => 'Dog',
                'breed' => 'Siberian Husky',
                'fur_type' => 'Hairless',
            ]],
        ], [
            'pets.*.fur_type' => [new ValidBreedCoat],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'The selected fur type is not available for this breed.',
            $validator->errors()->first('pets.0.fur_type'),
        );
    }

    public function test_valid_direct_coat_option_is_accepted_for_nested_pet(): void
    {
        $validator = Validator::make([
            'pets' => [[
                'species' => 'Dog',
                'breed' => 'Siberian Husky',
                'fur_type' => 'Wooly Coat',
            ]],
        ], [
            'pets.*.fur_type' => [new ValidBreedCoat],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_valid_direct_coat_option_is_accepted_for_clinic_pet(): void
    {
        $validator = Validator::make([
            'species' => 'Cat',
            'breed' => 'Sphynx',
            'fur_type' => 'Fine Down Coat',
        ], [
            'fur_type' => [new ValidBreedCoat],
        ]);

        $this->assertFalse($validator->fails());
    }
}
