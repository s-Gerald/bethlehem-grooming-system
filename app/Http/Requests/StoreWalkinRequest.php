<?php

namespace App\Http\Requests;

use App\Rules\ValidBreedCoat;
use App\Rules\ValidPetColor;
use App\Rules\ValidPetSize;
use App\Rules\ValidPetWeight;
use App\Support\AccountEmail;
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

        $email = AccountEmail::normalize((string) $this->input('email', ''));
        $phone = \App\Support\PhilippineMobile::normalize($this->input('phone'));

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
            'submission_key' => ['nullable', 'uuid'],
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
                ? \App\Support\PhilippineMobile::rules()
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
            'pets.*.color' => ['nullable', 'string', new ValidPetColor(allowExistingPetSelection: true)],
            'pets.*.medical_conditions' => ['nullable', 'string', 'max:1000'],
            'pets.*.special_instructions' => ['nullable', 'string', 'max:1000'],
            'pets.*.haircut_requested' => ['sometimes', 'boolean'],

            'pets.*.grooming_preference' => ['nullable', 'string', 'max:32'],
            'pets.*.groomer_id' => ['nullable', 'integer'],

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
            'phone.regex' => 'Phone number may only contain digits, spaces, +, -, and parentheses.',
        ];
    }

    private function normalizeText(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';
    }
}
