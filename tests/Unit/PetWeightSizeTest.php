<?php

namespace Tests\Unit;

use App\Models\Pet;
use App\Rules\ValidPetSize;
use App\Rules\ValidPetWeight;
use App\Support\PetWeightSize;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PetWeightSizeTest extends TestCase
{
    #[DataProvider('serializedWeights')]
    public function test_pet_weight_serializes_without_database_scale_padding(mixed $stored, mixed $expected): void
    {
        $pet = new Pet;
        $pet->setRawAttributes(['weight' => $stored], true);
        $this->assertSame($expected, $pet->toArray()['weight']);
        $this->assertSame($stored, $pet->getRawOriginal('weight'));
    }

    public static function serializedWeights(): array
    {
        return [
            ['4.100000000000', 4.1],
            ['7.000000000000', 7.0],
            ['20.000000000000', 20.0],
            ['4.999000000000', 4.999],
            [null, null],
        ];
    }

    public function test_verified_size_takes_priority_over_weight_estimate(): void
    {
        $pet = new Pet([
            'species' => 'Dog',
            'weight' => 12,
            'size' => 'small',
            'clinic_verified_fields' => ['size'],
        ]);
        $this->assertSame('small', $pet->groomingSize());

        $pet->clinic_verified_fields = null;
        $this->assertSame('medium', $pet->groomingSize());
    }

    #[DataProvider('acceptedWeights')]
    public function test_it_resolves_accepted_weights_to_the_expected_size(
        string $species,
        mixed $weight,
        string $expectedSize,
    ): void {
        $this->assertSame($expectedSize, PetWeightSize::sizeFor($species, $weight));
    }

    public static function acceptedWeights(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/weight-rules.json'), true)['pets'];
    }

    #[DataProvider('rejectedWeights')]
    public function test_it_rejects_invalid_submitted_weights(
        string $species,
        mixed $weight,
    ): void {
        $validator = Validator::make(['species' => $species, 'weight' => $weight], ['weight' => [new ValidPetWeight]]);
        $this->assertTrue($validator->fails());
    }

    public static function rejectedWeights(): array
    {
        return array_map(fn ($weight) => ['Dog', $weight],
            json_decode(file_get_contents(__DIR__.'/../Fixtures/weight-rules.json'), true)['invalid_weights']);
    }

    public function test_valid_weight_overrides_a_contradictory_manual_size(): void
    {
        $pet = PetWeightSize::withComputedSize([
            'species' => 'Dog',
            'weight' => 20,
            'size' => 'small',
        ]);

        $this->assertSame('medium', $pet['size']);
    }

    public function test_small_weights_are_valid_only_for_clinical_measurements_or_stored_size_estimation(): void
    {
        foreach (json_decode(file_get_contents(__DIR__.'/../Fixtures/weight-rules.json'), true)['below_profile_minimum'] as $weight) {
            $data = ['species' => 'Dog', 'weight' => $weight];
            $ordinary = Validator::make($data, ['weight' => [new ValidPetWeight]]);
            $clinical = Validator::make($data, ['weight' => [new ValidPetWeight(clinical: true)]]);
            $this->assertSame('Weight must be at least 0.5 kg.', $ordinary->errors()->first('weight'));
            $this->assertFalse($clinical->fails());
            $this->assertSame('small', PetWeightSize::sizeFor('Dog', $weight));
        }
    }

    public function test_missing_weight_preserves_manual_size_and_decimals_are_not_rounded(): void
    {
        $this->assertSame('large', PetWeightSize::withComputedSize(['species' => 'Dog', 'weight' => null, 'size' => 'large'])['size']);
        $this->assertSame('small', PetWeightSize::sizeFor('Cat', 4.999));
        $this->assertSame('small', PetWeightSize::sizeFor('Dog', 10.999));
        $this->assertSame('medium', PetWeightSize::sizeFor('Dog', 25.999));
    }

    public function test_precision_is_checked_before_converting_text_and_clinic_vitals_need_no_species(): void
    {
        foreach (['4.780', '4.200000', '10.555'] as $weight) {
            $validator = Validator::make(['weight_kg' => $weight], ['weight_kg' => [new ValidPetWeight(requireSpecies: false)]]);
            $this->assertSame('Enter weight using no more than 2 decimal places.', $validator->errors()->first('weight_kg'));
        }
        foreach (['4', '4.', '4.1', '4.20', '4.25', '155.6'] as $weight) {
            $validator = Validator::make(['weight_kg' => $weight], ['weight_kg' => [new ValidPetWeight(requireSpecies: false)]]);
            $this->assertFalse($validator->fails());
        }
    }

    public function test_it_computes_size_when_the_submission_omits_it(): void
    {
        $pet = PetWeightSize::withComputedSize([
            'species' => 'Dog',
            'weight' => 20,
        ]);

        $this->assertSame('medium', $pet['size']);
    }

    public function test_absolute_maximum_weight_uses_the_specific_validation_message(): void
    {
        $validator = Validator::make([
            'species' => 'Dog',
            'weight' => 155.61,
        ], [
            'weight' => ['numeric', new ValidPetWeight],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'Weight cannot be more than 155.6 kg.',
            $validator->errors()->first('weight'),
        );
    }

    public function test_nested_pet_weight_validation_uses_its_species(): void
    {
        $validator = Validator::make([
            'pets' => [[
                'species' => 'Cat',
                'weight' => 155.61,
            ]],
        ], [
            'pets.*.weight' => ['numeric', new ValidPetWeight],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'Weight cannot be more than 155.6 kg.',
            $validator->errors()->first('pets.0.weight'),
        );
    }

    public function test_single_pet_weight_validation_accepts_case_insensitive_species(): void
    {
        $validator = Validator::make([
            'species' => 'dog',
            'weight' => 51,
        ], [
            'weight' => ['numeric', new ValidPetWeight],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_cat_size_validation_rejects_dog_only_sizes(): void
    {
        $validator = Validator::make([
            'pets' => [[
                'species' => 'Cat',
                'size' => 'large',
            ]],
        ], [
            'pets.*.size' => [new ValidPetSize],
        ]);

        $this->assertTrue($validator->fails());
    }

    public function test_dog_size_validation_accepts_manual_extra_large_selection(): void
    {
        $validator = Validator::make([
            'species' => 'Dog',
            'size' => 'extra_large',
        ], [
            'size' => [new ValidPetSize],
        ]);

        $this->assertFalse($validator->fails());
    }
}
