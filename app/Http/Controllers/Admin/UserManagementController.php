<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest\StoreUserRequest;
use App\Http\Requests\UserRequest\UpdateUserRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DataTable\Column;
use App\Support\DataTable\DataTable;
use App\Support\Format;
use Illuminate\Support\Facades\Redirect;

class UserManagementController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index()
    {
        $isOwner = auth()->user()->isOwner();
        $me = auth()->user();

        $base = User::query();
        $users = (clone $base)->when(
            request()->query('role'),
            fn ($query, $role) => $query->where('role', $role)
        );

        $table = DataTable::for(request(), $users)
            ->searchable(['name', 'username', 'email'])
            ->sortable(['name', 'username', 'role', 'is_active', 'created_at'])
            ->columns([
                Column::make('name', 'Nama', sort: 'name')->priority(1)->card('title')->render(function (User $user) use ($me) {
                    $tag = $user->is($me)
                        ? '<span class="ml-1 rounded bg-canvas px-1.5 py-0.5 text-label-sm text-text-subtle">Anda</span>'
                        : '';

                    return '<span class="font-medium text-text-strong">'.e($user->name).'</span>'.$tag;
                }),
                Column::make('username', 'Username', sort: 'username')->mono()->priority(2)->card('meta'),
                Column::make('email', 'Email')->mono()->priority(2)->card('meta'),
                Column::make('role', 'Role')->priority(1)->card('badge')->render(function (User $user) {
                    $owner = $user->isOwner();
                    $classes = $owner
                        ? 'border-warning-border bg-warning-bg text-warning-text'
                        : 'border-info-border bg-info-bg text-info-text';

                    return '<span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-label-md '.$classes.'">'
                        .($owner ? 'Owner' : 'Staff').'</span>';
                }),
                Column::make('is_active', 'Status')->priority(1)->card('badge')->value(function (User $user) {
                    return $user->is_active ? 'ACTIVE' : 'INACTIVE';
                }),
                Column::make('actions', 'Aksi', align: 'right')
                    ->priority(1)
                    ->card('footer')
                    ->component('ui.row-actions', [
                        'actions' => array_values(array_filter([
                            [
                                'key' => 'edit',
                                'label' => 'Edit',
                                'route' => 'setting.pengguna.edit',
                                'when' => $isOwner,
                            ],
                            [
                                'key' => 'toggle',
                                'label' => 'Nonaktif',
                                'route' => 'setting.pengguna.toggle',
                                'method' => 'PATCH',
                                'when' => fn (User $user) => $isOwner && $user->is_active,
                                'confirm' => [
                                    'title' => 'Nonaktifkan pengguna?',
                                    'description' => 'Pengguna tidak dapat login lagi sampai diaktifkan kembali.',
                                    'confirm_text' => 'Nonaktifkan',
                                ],
                            ],
                            [
                                'key' => 'activate',
                                'label' => 'Aktifkan',
                                'route' => 'setting.pengguna.toggle',
                                'method' => 'PATCH',
                                'when' => fn (User $user) => $isOwner && ! $user->is_active,
                            ],
                        ])),
                    ]),
            ])
            ->filters([
                'role' => ['label' => 'Role', 'format' => fn (string $value) => Format::enum($value)],
            ]);

        if ($isOwner) {
            $table->create(route('setting.pengguna.create'), 'Tambah Pengguna');
        }

        $filtered = $table->hasActiveFilters();

        $table->emptyState(
            $filtered ? 'Pengguna tidak ditemukan' : 'Belum ada pengguna',
            $filtered
                ? 'Coba ubah kata kunci atau filter yang aktif.'
                : 'Tambahkan pengguna pertama melalui tombol Tambah Pengguna.'
        );

        return $this->page('pages.settings.pengguna', [
            'table' => $table,
            'totalUsers' => $base->toBase()->count(),
            'ownerCount' => $base->toBase()->where('role', Role::Owner->value)->count(),
            'inactiveCount' => $base->toBase()->where('is_active', false)->count(),
            'canManage' => $isOwner,
        ], 'Pengguna & Role');
    }

    public function create()
    {
        abort_unless(auth()->user()->isOwner(), 403);

        return $this->page('pages.settings.pengguna-form', ['user' => new User], 'Tambah Pengguna');
    }

    public function store(StoreUserRequest $request)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $data = $request->validated();
        $data['email_verified_at'] = isset($data['email']) ? now() : null;

        $user = User::create($data);
        $this->audit->created($user);

        return Redirect::route('setting.pengguna')->with('toast', [
            'type' => 'success',
            'message' => 'Pengguna '.$user->name.' ditambahkan.',
        ]);
    }

    public function edit(User $user)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        return $this->page('pages.settings.pengguna-form', ['user' => $user], 'Edit Pengguna');
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $this->ensureOwnershipSafe($user, $request);

        $before = $user->getAttributes();
        $data = collect($request->validated())->except(['password', 'pin'])->all();

        if ($request->filled('password')) {
            $data['password'] = $request->input('password');
        }
        if ($request->filled('pin')) {
            $data['pin'] = $request->input('pin');
        }

        $user->fill($data)->save();
        $this->audit->updated($user, $before);

        return Redirect::route('setting.pengguna')->with('toast', [
            'type' => 'success',
            'message' => 'Pengguna '.$user->name.' diperbarui.',
        ]);
    }

    public function toggleActive(User $user)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        if ($user->is(auth()->user())) {
            return back()->with('toast', ['type' => 'error', 'message' => 'Anda tidak dapat menonaktifkan akun sendiri.']);
        }

        $user->update(['is_active' => ! $user->is_active]);
        $this->audit->log('ACTIVE', class_basename($user), $user->getKey(), [], ['is_active' => $user->is_active]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Pengguna '.$user->name.' '.($user->is_active ? 'diaktifkan' : 'dinonaktifkan').'.',
        ]);
    }

    private function ensureOwnershipSafe(User $user, UpdateUserRequest $request): void
    {
        if ($user->is(auth()->user()) && $request->input('role') !== $user->role->value) {
            Redirect::back()->withInput()->withErrors(['role' => 'Anda tidak dapat mengubah role akun sendiri.'])->throwResponse();
        }

        if ($user->isOwner() && $request->input('role') !== 'OWNER' && User::where('role', 'OWNER')->count() <= 1) {
            Redirect::back()->withInput()->withErrors(['role' => 'Minimal satu Owner harus tetap ada.'])->throwResponse();
        }
    }
}
