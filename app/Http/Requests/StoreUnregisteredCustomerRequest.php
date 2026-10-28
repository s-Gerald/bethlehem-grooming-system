<?php

namespace App\Http\Requests;

use App\Support\AccountEmail;
use Illuminate\Foundation\Http\FormRequest;

class StoreUnregisteredCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = AccountEmail::normalize((string) $this->input('email', ''));
        $middleName = strtoupper(rtrim($this->normalizeText($this->input('middle_name')), '.'));
        $phone = \App\Support\PhilippineMobile::normalize($this->input('phone'));

        $this->merge([
            'first_name' => is_string($this->input('first_name')) ? \App\Support\HumanName::normalize($this->input('first_name')) : $this->input('first_name'),
            'last_name' => is_string($this->input('last_name')) ? \App\Support\HumanName::normalize($this->input('last_name')) : $this->input('last_name'),
            'middle_name' => $middleName !== '' ? $middleName : null,
            'phone' => $phone,
            'email' => $email !== '' ? $email : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name' => \App\Support\HumanName::rules(),
            'last_name' => \App\Support\HumanName::rules(),
            'middle_name' => ['nullable', 'string', 'max:5'],
            'phone' => \App\Support\PhilippineMobile::rules(),
            'email' => AccountEmail::rules(required: false, maxLength: $this->is('api/admin/walk-in/customers/validate-new-owner') ? 255 : 150),
            'confirm_similar_name' => ['sometimes', 'boolean'],
        ];
    }

    private function normalizeText(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';
    }
}
