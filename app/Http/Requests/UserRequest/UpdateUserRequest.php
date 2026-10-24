<?php

namespace App\Http\Requests\UserRequest;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($this->route('user'))],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'role' => ['required', Rule::in($this->assignableRoleValues())],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
        ];
    }

    /**
     * Role yang boleh dikirim aktor ini.
     *
     * Sama dengan `StoreUserRequest`: Owner tidak boleh menetapkan Developer,
     * termasuk lewat request yang dibuat tangan. Tanpa aktor, tidak ada role
     * yang sah.
     *
     * @return list<string>
     */
    private function assignableRoleValues(): array
    {
        $user = $this->user();

        if ($user === null) {
            return [];
        }

        return array_map(
            static fn (Role $role): string => $role->value,
            $user->assignableRoles(),
        );
    }
}
