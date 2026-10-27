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
        $phone = $this->normalizePhone($this->input('phone'));

        $this->merge([
            'first_name' => $this->normalizeText($this->input('first_name')),
            'last_name' => $this->normalizeText($this->input('last_name')),
            'middle_name' => $middleName !== '' ? $middleName : null,
            'phone' => $phone,
            'email' => $email !== '' ? $email : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:5'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^09\d{9}$/'],
            'email' => AccountEmail::rules(required: false, maxLength: $this->is('api/admin/walk-in/customers/validate-new-owner') ? 255 : 150),
            'confirm_similar_name' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Phone number must be 11 digits and start with 09.',
        ];
    }

    private function normalizeText(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';
    }

    private function normalizePhone(mixed $value): string
    {
        $raw = $this->normalizeText($value);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (preg_match('/^63\d{10}$/', $digits)) {
            return '0'.substr($digits, 2);
        }

        if (preg_match('/^9\d{9}$/', $digits)) {
            return '0'.$digits;
        }

        return $digits;
    }
}
