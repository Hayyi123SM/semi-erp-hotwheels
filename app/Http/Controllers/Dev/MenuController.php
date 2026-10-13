<?php

namespace App\Http\Controllers\Dev;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\FeatureVisibility;
use App\Services\AuditLogger;
use App\Support\Visibility\MenuVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MenuController extends Controller
{
    public function __construct(private readonly MenuVisibility $visibility, private readonly AuditLogger $auditLogger) {}

    public function index(Request $request)
    {
        $role = $this->resolveRole($request->input('role'));
        $catalog = $this->visibility->catalog();
        $overrideMap = $this->visibility->overrideMap();
        $groups = config('visibility.groups', []);
        $orderedGroups = collect($groups)->sortBy('sort')->keys();

        $itemsByGroup = $catalog
            ->groupBy(fn (array $item) => $item['group'] ?? 'other')
            ->map(fn (Collection $col) => $col->sortBy(fn (array $i) => $i['label'] ?? $i['key']));

        return $this->page('pages.dev.menu', [
            'role' => $role->value,
            'roles' => collect([Role::Owner, Role::Staff])->map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()])->values(),
            'groups' => $orderedGroups->values(),
            'itemsByGroup' => $itemsByGroup,
            'overrideMap' => $overrideMap,
            'locked' => fn (string $key, Role $r): bool => $this->isLocked($key, $r),
        ], 'Kelola Menu & Fitur (DEV)');
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        $roleEnum = $this->resolveRole($role);
        $data = $request->validate([
            'visible' => ['nullable', 'array'],
            'visible.*' => ['boolean'],
        ]);
        $visibleMap = $data['visible'] ?? [];
        $before = $this->visibility->overrideMap()[$roleEnum->value] ?? [];
        $after = $before;
        $catalog = $this->visibility->catalog();
        foreach ($catalog as $key => $meta) {
            if (! array_key_exists($key, $visibleMap)) {
                continue;
            }
            $value = (bool) $visibleMap[$key];
            if ($this->isLocked($key, $roleEnum)) {
                continue;
            }
            $defaults = (array) ($meta['default'] ?? []);
            $defaultValue = array_key_exists($roleEnum->value, $defaults) ? (bool) $defaults[$roleEnum->value] : false;
            if ($value === $defaultValue) {
                FeatureVisibility::query()->where('key', $key)->where('role', $roleEnum->value)->delete();
                unset($after[$key]);
            } else {
                FeatureVisibility::query()->updateOrCreate(
                    ['key' => $key, 'role' => $roleEnum->value],
                    ['visible' => $value, 'updated_by' => $request->user()?->id]
                );
                $after[$key] = $value;
            }
        }
        $this->visibility->forgetCache();
        $setDiff = collect($after)->diffAssoc($before)->toArray();
        $unsetDiff = collect($before)->diffAssoc($after)->keys()->values()->all();
        $this->auditLogger->log('UPDATE_VISIBILITY', 'FeatureVisibility', [
            'role' => $roleEnum->value,
            'set' => $setDiff,
            'unset' => $unsetDiff,
        ], null, null, $request->user());

        return redirect()->route('dev.menu', ['role' => $roleEnum->value])
            ->with('toast', ['type' => 'success', 'message' => 'Visibilitas menu diperbarui.']);
    }

    public function reset(Request $request, string $role): RedirectResponse
    {
        $roleEnum = $this->resolveRole($role);
        FeatureVisibility::query()->where('role', $roleEnum->value)->delete();
        $this->visibility->forgetCache();
        $this->auditLogger->log('RESET_VISIBILITY', 'FeatureVisibility', ['role' => $roleEnum->value], null, null, $request->user());

        return redirect()->route('dev.menu', ['role' => $roleEnum->value])
            ->with('toast', ['type' => 'success', 'message' => 'Visibilitas dikembalikan ke default.']);
    }

    private function resolveRole($role): Role
    {
        $value = is_string($role) ? strtoupper($role) : '';

        return match ($value) {
            'STAFF' => Role::Staff,
            'OWNER' => Role::Owner,
            default => Role::Owner,
        };
    }

    private function isLocked(string $key, Role $role): bool
    {
        $catalog = $this->visibility->catalog();
        $meta = $catalog->get($key);
        if ($meta === null) {
            return true;
        }
        $locked = (array) ($meta['locked'] ?? []);
        if (array_key_exists($role->value, $locked)) {
            return (bool) $locked[$role->value];
        }

        return false;
    }
}
