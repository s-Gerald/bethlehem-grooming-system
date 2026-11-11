<?php

namespace App\Http\Requests;

use App\Models\ClinicVisitGroup;
use App\Rules\ValidBreedCoat;
use App\Rules\ValidPetColor;
use App\Rules\ValidPetBirthdate;
use App\Rules\ValidPetSize;
use App\Support\PetBodyCondition;
use App\Rules\ValidPetWeight;
use App\Support\ClinicConcerns;
use App\Support\AccountEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Illuminate\Validation\Rule;

class StoreClinicWalkinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (($this->input('owner_record_type') ?? 'new') !== 'new') {
            return;
        }

        $email = AccountEmail::normalize((string) $this->input('email', ''));
        $phone = \App\Support\PhilippineMobile::normalize($this->input('phone'));

        $this->merge([
            'fname' => is_string($this->input('fname')) ? \App\Support\HumanName::normalize($this->input('fname')) : $this->input('fname'),
            'lname' => is_string($this->input('lname')) ? \App\Support\HumanName::normalize($this->input('lname')) : $this->input('lname'),
            'email' => $email !== '' ? $email : null,
            'phone' => $phone,
        ]);
    }

    public function rules(): array
    {
        return [
            // Owner
            // Selected owners use persisted identity; these submitted names are not editable or saved.
            'fname' => ($this->input('owner_record_type') ?? 'new') === 'new'
                ? \App\Support\HumanName::rules('first_name') : ['required', 'string'],
            'lname' => ($this->input('owner_record_type') ?? 'new') === 'new'
                ? \App\Support\HumanName::rules('last_name') : ['required', 'string'],
            'email' => ($this->input('owner_record_type') ?? 'new') === 'new'
                ? AccountEmail::rules(required: false, maxLength: 255)
                : ['nullable', 'string', 'max:255'],
            'phone' => ($this->input('owner_record_type') ?? 'new') === 'new'
                ? \App\Support\PhilippineMobile::rules()
                : ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'owner_record_type' => ['nullable', 'in:new,registered,unregistered'],
            'customer_user_id' => ['nullable', 'integer', 'exists:users,user_id'],
            'unregistered_customer_id' => ['nullable', 'integer', 'exists:unregistered_customers,id'],
            'confirm_similar_name' => ['sometimes', 'boolean'],

            ...($this->exists('patients') ? [
                'patients' => ['required', 'array', 'list', 'min:1', 'max:'.ClinicVisitGroup::MAX_PATIENTS],
            ] : $this->patientRules($this->all(), legacy: true)),

            'clinic_quick_entry' => ['sometimes', 'boolean'],
            'terms_agreed' => ['required', 'boolean', 'accepted'],
        ];
    }

    private function patientRules(array $patient, bool $legacy = false): array
    {
        return [
            'pet_id' => ['nullable', 'integer', ...($legacy ? ['exists:pets,pet_id'] : [])],
            'pet_name' => ['required', 'string', 'max:100'],
            'species' => ['required', 'string', 'max:100'],
            'breed' => [
                Rule::requiredIf($this->boolean('clinic_quick_entry') && empty($patient['pet_id'])),
                'nullable',
                'string',
                'max:100',
            ],
            'gender' => ['nullable', 'in:male,female'],
            'birthdate' => ['nullable', new ValidPetBirthdate(allowExistingPetSelection: true)],
            'is_neutered' => ['nullable', 'boolean'],
            'neutered_date' => ['nullable', 'date'],
            'fur_type' => ['nullable', 'string', 'max:100', new ValidBreedCoat(allowExistingPetSelection: true)],
            'weight' => ['nullable', new ValidPetWeight(allowExistingPetSelection: true)],
            'body_condition' => PetBodyCondition::rules(),
            'size' => ['nullable', 'in:small,medium,large,extra_large', new ValidPetSize],
            'color' => ['nullable', 'string', new ValidPetColor(allowExistingPetSelection: true)],
            'medical_conditions' => ['nullable', 'string', 'max:500'],

            ...ClinicConcerns::rules(),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->exists('patients') || $validator->errors()->isNotEmpty()) {
                return;
            }

            $seen = [];
            foreach ($this->input('patients') as $index => $patient) {
                if (! is_array($patient)) {
                    $validator->errors()->add("patients.{$index}", 'Enter valid patient information.');
                    continue;
                }
                $row = validator($patient, $this->patientRules($patient), $this->messages());
                if ($row->fails()) {
                    foreach ($row->errors()->messages() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add("patients.{$index}.{$field}", $message);
                        }
                    }
                    continue;
                }

                $id = $row->validated()['pet_id'] ?? null;
                if ($id !== null && isset($seen[$id])) {
                    $validator->errors()->add("patients.{$index}.pet_id", 'Select each pet only once.');
                }
                if ($id !== null) {
                    $seen[$id] = true;
                }
            }
        }];
    }

    public function patients(): array
    {
        if (! $this->exists('patients')) {
            return [$this->validated()];
        }

        return array_map(
            fn (array $patient) => array_intersect_key($patient, $this->patientRules($patient)),
            $this->validated('patients'),
        );
    }

    public function messages(): array
    {
        return [
            'terms_agreed.accepted' => 'The customer must agree to the terms before registration.',
            'common_concerns.required' => 'Select at least one common concern.',
            'common_concerns.min' => 'Select at least one common concern.',
            'common_concerns.*.in' => 'Select a valid common concern.',
            'phone.regex' => 'Phone number may only contain digits, spaces, +, -, and parentheses.',
        ];
    }
}
