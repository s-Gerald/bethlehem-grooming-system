<?php

namespace App\Http\Requests;

use App\Rules\ValidBreedCoat;
use App\Rules\ValidPetSize;
use App\Rules\ValidPetWeight;
use Illuminate\Foundation\Http\FormRequest;

class StoreWalkinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Access is gated by auth:sanctum middleware on the route
    }

    protected function prepareForValidation(): void
    {
        if (($this->input('owner_record_type') ?? 'new') !== 'new') {
            return;
        }

        $email = strtolower(trim((string) $this->input('email', '')));
        $phone = preg_replace('/\D+/', '', trim((string) $this->input('phone', ''))) ?? '';

        if (preg_match('/^63\d{10}$/', $phone)) {
            $phone = '0'.substr($phone, 2);
        } elseif (preg_match('/^9\d{9}$/', $phone)) {
            $phone = '0'.$phone;
        }

        $this->merge([
            'fname' => $this->normalizeText($this->input('fname')),
            'lname' => $this->normalizeText($this->input('lname')),
            'mname' => $this->normalizeText($this->input('mname')) ?: null,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone,
        ]);
    }

    public function rules(): array
    {
        return [
            // Owner
            'fname' => ['required', 'string', 'max:100'],
            'lname' => ['required', 'string', 'max:100'],
            'mname' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ($this->input('owner_record_type') ?? 'new') === 'new'
                ? ['required', 'string', 'max:20', 'regex:/^09\d{9}$/']
                : ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'owner_record_type' => ['nullable', 'in:new,registered,unregistered'],
            'customer_user_id' => ['nullable', 'integer', 'exists:users,user_id'],
            'unregistered_customer_id' => ['nullable', 'integer', 'exists:unregistered_customers,id'],
            'confirm_similar_name' => ['sometimes', 'boolean'],

            // Pets — at least one required, max 10 (matches frontend limit)
            'pets' => ['required', 'array', 'min:1', 'max:10'],
            'pets.*.pet_name' => ['required', 'string', 'max:100'],
            'pets.*.pet_id' => ['nullable', 'integer', 'exists:pets,pet_id'],
            'pets.*.species' => ['required', 'string', 'max:100'],
            'pets.*.breed' => ['nullable', 'string', 'max:100'],
            'pets.*.fur_type' => ['nullable', 'string', 'max:100', new ValidBreedCoat(allowExistingPetSelection: true)],
            'pets.*.weight' => ['nullable', new ValidPetWeight],
            'pets.*.size' => ['nullable', 'in:small,medium,large,extra_large', new ValidPetSize],
            'pets.*.medical_conditions' => ['nullable', 'string', 'max:1000'],
            'pets.*.special_instructions' => ['nullable', 'string', 'max:1000'],

            'pets.*.grooming_preference' => ['nullable', 'string', 'max:32'],
            'pets.*.groomer_id' => ['nullable', 'integer'],
            'pets.*.estimate_factors' => ['nullable', 'array'],
            'pets.*.estimate_factors.*' => ['string', 'in:'.implode(',', array_keys(config('grooming_estimates.factors')))],

            // Services per pet — at least one per pet, each validated against the DB
            'pets.*.services' => ['required', 'array', 'min:1', 'max:20'],
            'pets.*.services.*.service_slug' => ['required', 'string', 'exists:services,slug'],

            // Consent
            ...\App\Services\GroomingConsentCapture::rules(),
        ];
    }

    public function messages(): array
    {
        return [
            'terms_agreed.accepted' => 'The customer must agree to the terms before the walk-in can be registered.',
            'pets.required' => 'At least one pet must be included in the walk-in.',
            'pets.min' => 'At least one pet must be included in the walk-in.',
            'pets.*.services.min' => 'Each pet must have at least one grooming service selected.',
            'pets.*.services.*.service_slug.exists' => 'One or more selected services were not found. Please refresh and try again.',
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
