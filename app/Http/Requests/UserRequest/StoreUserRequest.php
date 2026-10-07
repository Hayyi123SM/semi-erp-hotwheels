<?php

namespace App\Http\Requests\UserRequest;

use App\Enums\Role;
use App\Support\Enums;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(Enums::values(Role::class))],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['required', 'string', 'min:8'],
            'pin' => ['nullable', 'digits:6'],
        ];
    }
}
