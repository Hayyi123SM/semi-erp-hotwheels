@php
    use App\Enums\Role;
    $currentRole = Role::tryFrom(strtoupper($role ?? 'OWNER')) ?? Role::Owner;
    $overrideMap = $overrideMap[$currentRole->value] ?? [];
@endphp

<x-ui.page-header
    title="Kelola Menu & Fitur (DEV)"
    subtitle="Hanya mempersempit akses, tidak memperluas. Perubahan memblokir rute, bukan sekadar menyembunyikan."
    :crumbs="['Dev', 'Menu']"
>
</x-ui.page-header>

<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-2">
        @foreach ($roles as $r)
            <a href="{{ route('dev.menu', ['role' => $r['value']]) }}"
               class="btn-ghost {{ $r['value'] === $currentRole->value ? '!text-primary' : '' }}">
                {{ $r['label'] }}
            </a>
        @endforeach
    </div>

    <form method="POST" action="{{ route('dev.menu.update', $currentRole->value) }}">
        @csrf
        @method('PUT')

        <div class="card divide-y divide-border-subtle">
            @foreach ($groups as $groupKey)
                @php
                    $items = $itemsByGroup->get($groupKey, collect());
                @endphp
                @if ($items->isNotEmpty())
                    <div class="px-5 py-4">
                        <h2 class="text-headline-sm text-text-strong">{{ config('visibility.groups.'.$groupKey.'.label') ?? $groupKey }}</h2>
                        <div class="mt-3 space-y-2">
                            @foreach ($items as $item)
                                @php
                                    $key = $item['key'];
                                    $isLocked = $locked($key, $currentRole);
                                    $defaults = (array) ($item['default'] ?? []);
                                    $defaultValue = array_key_exists($currentRole->value, $defaults) ? (bool) $defaults[$currentRole->value] : false;
                                    $currentValue = array_key_exists($key, $overrideMap) ? (bool) $overrideMap[$key] : $defaultValue;
                                @endphp
                                <label class="flex items-center justify-between rounded-lg border border-border-subtle px-3 py-2">
                                    <div>
                                        <p class="text-body-md font-medium text-text-strong">{{ $item['label'] }}</p>
                                        <p class="text-label-sm text-text-muted">{{ $key }}{{ $item['route'] ? ' · '.$item['route'] : '' }}</p>
                                    </div>
                                    <input type="checkbox" name="visible[{{ $key }}]" value="1" @checked($currentValue) @disabled($isLocked)>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </div>

        <div class="mt-4 flex justify-end gap-3">
            <form method="POST" action="{{ route('dev.menu.reset', $currentRole->value) }}">
                @csrf
                <button type="submit" class="btn-secondary">Reset tab ini</button>
            </form>
            <button type="submit" class="btn-primary">Simpan tab ini</button>
        </div>
    </form>
</div>
