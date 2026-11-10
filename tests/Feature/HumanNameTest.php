<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\HumanName;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class HumanNameTest extends TestCase
{
    public function test_field_specific_boundaries_and_messages(): void
    {
        foreach (['first_name' => 40, 'last_name' => 80] as $type => $limit) {
            $this->assertNull(HumanName::error(str_repeat('É', $limit), $type));
            $this->assertNotNull(HumanName::error(str_repeat('É', $limit + 1), $type));
            $this->assertSame('Enter your '.str_replace('_', ' ', $type).'.', HumanName::error('', $type));
        }
    }
    public function test_name_casing_conforms_to_shared_examples(): void
    {
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/human-names.json')), true) as [$input, $expected]) {
            $this->assertSame($expected, User::normalizeName($input), $input);
        }
    }

    public function test_shared_validation_rejects_numbers_controls_and_symbols(): void
    {
        foreach (['Gerald123', 'Maria٢ Clara', '１２３', 'Ⅷ', '²', "Jo\nSmith", "Jo\0Smith", '---', 'ʼ', '💙', ['name']] as $name) {
            $this->assertTrue(Validator::make(['name' => $name], ['name' => HumanName::rules()])->fails());
        }
        foreach (["O’Neill", 'Anne-Marie', 'Peña', '李', "élodie", 'de la Cruz'] as $name) {
            $this->assertFalse(Validator::make(['name' => $name], ['name' => HumanName::rules()])->fails(), $name);
        }
    }

    public function test_all_new_owner_requests_use_the_shared_name_rules(): void
    {
        foreach ([\App\Http\Requests\StoreUnregisteredCustomerRequest::class => ['first_name', 'last_name'],
            \App\Http\Requests\StoreWalkinRequest::class => ['fname', 'lname'],
            \App\Http\Requests\StoreClinicWalkinRequest::class => ['fname', 'lname']] as $requestClass => $fields) {
            $request = new $requestClass;
            $rules = array_intersect_key($request->rules(), array_flip($fields));
            $this->assertFalse(Validator::make(array_fill_keys($fields, 'de la Cruz'), $rules)->fails());
            $this->assertFalse(Validator::make(array_fill_keys($fields, 'Peña'), $rules)->fails());
            $this->assertTrue(Validator::make(array_fill_keys($fields, 'Maria٢'), $rules)->fails());
            $this->assertTrue(Validator::make(array_fill_keys($fields, ''), $rules)->fails());
            $this->assertFalse(Validator::make([$fields[0] => str_repeat('É', 40), $fields[1] => str_repeat('É', 80)], $rules)->fails());
            foreach ([$fields[0] => 41, $fields[1] => 81] as $field => $length) {
                $this->assertArrayHasKey($field, Validator::make([$fields[0] => 'Ana', $fields[1] => 'Cruz', $field => str_repeat('É', $length)], $rules)->errors()->toArray());
            }
            if ($fields[0] === 'fname') {
                $request->merge(['owner_record_type' => 'registered']);
                $rules = array_intersect_key($request->rules(), array_flip($fields));
                $this->assertFalse(Validator::make(array_fill_keys($fields, str_repeat('Legacy2', 20)), $rules)->fails(),
                    'Selected owners resolve their persisted identity without rewriting historical names.');
            }
        }
    }
}
