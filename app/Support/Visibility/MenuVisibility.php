<?php

declare(strict_types=1);

namespace App\Support\Visibility;

use App\Models\FeatureVisibility;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class MenuVisibility
{
    private const string CACHE_KEY = 'visibility.matrix';

    public function canSee(?User $user, string $key): bool
    {
        if ($user === null) {
            return false;
        }

        $catalog = $this->catalog();
        $meta = $catalog->get($key);
        if ($meta === null) {
            return true;
        }

        $role = $user->role;
        if ($role === null) {
            return false;
        }

        $roleValue = $role->value;
        $baseAllowed = $this->baseRoleAllowed($user, $key, $meta);

        if (! $baseAllowed) {
            return false;
        }

        $locked = (array) ($meta['locked'] ?? []);
        if ($this->boolFromKey($locked, $roleValue)) {
            return true;
        }

        $overrideMap = $this->overrideMap();
        if (isset($overrideMap[$roleValue][$key])) {
            return (bool) $overrideMap[$roleValue][$key];
        }

        $defaults = (array) ($meta['default'] ?? []);
        if ($this->boolFromKey($defaults, $roleValue)) {
            return (bool) $this->boolFromKey($defaults, $roleValue, null);
        }

        return false;
    }

    public function menuVisible(?User $user, string $routeName): bool
    {
        if ($user === null || $routeName === '') {
            return false;
        }

        return $this->canSee($user, $routeName);
    }

    public function featureVisible(?User $user, string $key): bool
    {
        return $this->canSee($user, $key);
    }

    public function routeBlocked(?User $user, string $routeName): bool
    {
        return ! $this->menuVisible($user, $routeName);
    }

    public function catalog(): Collection
    {
        $config = config('visibility.keys', []);
        $items = [];
        foreach ($config as $key => $meta) {
            $items[$key] = array_merge(['key' => $key], $meta);
        }

        return collect($items);
    }

    public function overrideMap(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $map = [
                'OWNER' => [],
                'STAFF' => [],
                'DEV' => [],
            ];
            FeatureVisibility::query()->get()->each(function (FeatureVisibility $fv) use (&$map): void {
                $role = $fv->role;
                if (isset($map[$role])) {
                    $map[$role][$fv->key] = (bool) $fv->visible;
                }
            });

            return $map;
        });
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function boolFromKey(array $map, string $roleValue, ?bool $default = false): ?bool
    {
        if (array_key_exists($roleValue, $map)) {
            return (bool) $map[$roleValue];
        }
        $lower = strtolower($roleValue);
        if (array_key_exists($lower, $map)) {
            return (bool) $map[$lower];
        }

        return $default;
    }

    private function baseRoleAllowed(User $user, string $key, array $meta): bool
    {
        $type = $meta['type'] ?? 'menu';
        if ($user->isDev()) {
            if ($type === 'system') {
                return true;
            }

            // Developer adalah pengelola akun: "Pengguna & Role" harus terlihat
            // supaya mereka bisa membuat akun Developer lain. Menu lain tetap
            // milik dunia `dev.*` dan tidak ikut terbuka.
            return $key === 'dev.menu'
                || str_starts_with($key, 'dev.')
                || $key === 'setting.pengguna';
        }
        if ($user->isOwner()) {
            return true;
        }
        if ($user->isStaff()) {
            $route = $meta['route'] ?? null;
            if ($route === 'pos.kasir' || $route === 'pos.riwayat' || $route === 'pos.shift-kasir') {
                return true;
            }
            if ($key === 'home' || $key === 'dashboard' || $key === 'profile.edit') {
                return true;
            }

            return false;
        }

        return false;
    }
}
