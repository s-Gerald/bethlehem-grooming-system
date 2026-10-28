<?php

namespace App\Http\Requests;

use App\Rules\ValidBreedCoat;
use App\Rules\ValidPetColor;
use App\Rules\ValidPetSize;
use App\Rules\ValidPetWeight;
use App\Support\ClinicConcerns;
use App\Support\AccountEmail;
use Illuminate\Foundation\Http\FormRequest;
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
        $phone = preg_replace('/\D+/', '', trim((string) $this->input('phone', ''))) ?? '';

        if (preg_match('/^63\d{10}$/', $phone)) {
            $phone = '0'.substr($phone, 2);
        } elseif (preg_match('/^9\d{9}$/', $phone)) {
            $phone = '0'.$phone;
        }

        $this->merge([
            'fname' => is_string($this->input('fname')) ? \App\Support\HumanName::normalize($this->input('fname')) : $this->input('fname'),
            'lname' => is_string($this->input('lname')) ? \App\Support\HumanName::normalize($this->input('lname')) : $this->input('lname'),
            'mname' => $this->normalizeText($this->input('mname')) ?: null,
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
                ? \App\Support\HumanName::rules() : ['required', 'string', 'max:100'],
            'lname' => ($this->input('owner_record_type') ?? 'new') === 'new'
                ? \App\Support\HumanName::rules() : ['required', 'string', 'max:100'],
            'mname' => ['nullable', 'string', 'max:100'],
            'email' => ($this->input('owner_record_type') ?? 'new') === 'new'
                ? AccountEmail::rules(required: false, maxLength: 255)
                : ['nullable', 'string', 'max:255'],
            'phone' => ($this->input('owner_record_type') ?? 'new') === 'new'
                ? ['required', 'string', 'max:20', 'regex:/^09\d{9}$/']
                : ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'owner_record_type' => ['nullable', 'in:new,registered,unregistered'],
            'customer_user_id' => ['nullable', 'integer', 'exists:users,user_id'],
            'unregistered_customer_id' => ['nullable', 'integer', 'exists:unregistered_customers,id'],
            'confirm_similar_name' => ['sometimes', 'boolean'],

            // Pet — single pet for clinic walk-in
            'pet_id' => ['nullable', 'integer', 'exists:pets,pet_id'],
            'pet_name' => ['required', 'string', 'max:100'],
            'species' => ['required', 'string', 'max:100'],
            'breed' => [
                Rule::requiredIf($this->boolean('clinic_quick_entry') && ! $this->filled('pet_id')),
                'nullable',
                'string',
                'max:100',
            ],
            'gender' => ['nullable', 'in:male,female'],
            'birthdate' => ['nullable', 'date'],
            'is_neutered' => ['nullable', 'boolean'],
            'neutered_date' => ['nullable', 'date'],
            'fur_type' => ['nullable', 'string', 'max:100', new ValidBreedCoat(allowExistingPetSelection: true)],
            'weight' => ['nullable', new ValidPetWeight],
            'size' => ['nullable', 'in:small,medium,large,extra_large', new ValidPetSize],
            'color' => ['nullable', 'string', new ValidPetColor(allowExistingPetSelection: true)],
            'medical_conditions' => ['nullable', 'string', 'max:1000'],

            // Clinic
            'common_concerns' => ['required', 'array', 'min:1', 'max:11'],
            'common_concerns.*' => ['required', 'string', 'distinct', Rule::in(ClinicConcerns::OPTIONS)],
            'chief_complaint' => ['nullable', 'string', 'max:1000'],
            'clinic_quick_entry' => ['sometimes', 'boolean'],

            // Consent
            'terms_agreed' => ['required', 'boolean', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'terms_agreed.accepted' => 'The customer must agree to the terms before registration.',
            'common_concerns.required' => 'Select at least one common concern.',
            'common_concerns.min' => 'Select at least one common concern.',
            'common_concerns.*.in' => 'Select a valid common concern.',
            'phone.regex' => ($this->input('owner_record_type') ?? 'new') === 'new'
                ? 'Phone number must be 11 digits and start with 09.'
                : 'Phone number may only contain digits, spaces, +, -, and parentheses.',
        ];
    }

    private function normalizeText(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';
    }
}
