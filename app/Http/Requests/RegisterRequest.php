<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\NigerianPhone;
use App\Rules\Pin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the phone before anything is validated, so `validated()`
     * carries the canonical form and the uniqueness check below compares like
     * with like. Without this, the same number written two ways would pass the
     * unique rule twice and become two accounts.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->phone)) {
            $this->merge([
                'phone' => NigerianPhone::normalise($this->phone) ?? $this->phone,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],

            // Optional, because most tailors have never had one.
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],

            'phone' => ['required', 'string', new NigerianPhone, 'unique:users,phone'],

            'pin' => ['required', 'confirmed', new Pin($this->input('phone'))],

            'role' => ['required', Rule::in(User::SELF_SIGNUP_ROLES)],

            // Only a tailor has a shop, and she cannot be listed without a name for it.
            'business_name' => ['required_if:role,tailor', 'nullable', 'string', 'max:160'],
            'location' => ['required_if:role,tailor', 'nullable', 'string', 'max:160'],
            'state' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_name.required_if' => 'What is your shop called? Customers will see this.',
            'location.required_if' => 'Where are you? Customers search by area.',
            'pin.confirmed' => 'The two PINs are not the same.',
        ];
    }
}
