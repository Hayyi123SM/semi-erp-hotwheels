<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'regex:/^[a-zA-Z0-9._-]+$/', 'max:50', 'unique:users,username'],
            'email' => ['nullable', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Role is written here rather than left to the column default because
        // the two were allowed to disagree: the default was lowercase `staff`
        // while the enum reads `STAFF`, so a registered user could be persisted
        // in a form `isOwner()` cannot interpret. Staff is also the only answer
        // this form may give -- a self-service signup that accepts a role field
        // is an open door to Owner, whatever the default happens to say.
        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->filled('email') ? $request->email : null,
            'password' => Hash::make($request->password),
            'role' => Role::Staff,
            'is_active' => true,
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
