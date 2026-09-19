<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * One box, not two.
     *
     * `identifier` is deliberately validated as a plain string rather than as a
     * phone number or an email address. Rejecting a malformed one here would
     * tell an attacker which shapes we recognise, and more practically it would
     * refuse somebody at the door for a typo before they ever reached the PIN
     * check -- with a message different from the one a wrong PIN gets, which is
     * itself a way of enumerating accounts.
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string'],
            'pin' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
