@props(['active', 'title', 'intro' => null])
@php
    $isAdmin = auth()->user()?->isAdmin();
    $groups = [
        'Konto' => [['settings.account', 'Mein Konto', true]],
        'Betrieb' => [
            ['settings.general', 'Allgemein', false],
            ['settings.users', 'Benutzer', false],
            ['settings.schedule', 'Abgleich und Zeitplan', false],
            ['settings.backups', 'Sicherung', false],
        ],
        'Verbindungen' => [
            ['settings.shopify', 'Shopify', false],
            ['settings.billbee', 'Billbee', false],
            ['settings.ebay', 'eBay', false],
            ['settings.amazon', 'Amazon', false],
        ],
    ];
@endphp
<div>
    <div class="page-head">
        <h1>{{ $title }}</h1>
    </div>
    <div class="settings-layout">
        <nav class="settings-nav" aria-label="Bereiche der Einstellungen">
            @if ($isAdmin)
                <a href="{{ route('settings') }}" wire:navigate @if ($active === 'settings') aria-current="page" @endif>Übersicht</a>
            @endif
            @foreach ($groups as $label => $items)
                @php $visible = collect($items)->filter(fn ($i) => $i[2] || $isAdmin); @endphp
                @if ($visible->isNotEmpty())
                    <div class="settings-nav-group">{{ $label }}</div>
                    @foreach ($visible as [$route, $name])
                        <a href="{{ route($route) }}" wire:navigate @if ($active === $route) aria-current="page" @endif>{{ $name }}</a>
                    @endforeach
                @endif
            @endforeach
            <a href="{{ route('pricing') }}" wire:navigate class="muted">Kanäle und Gebühren ›</a>
        </nav>
        <div class="settings-content">
            @if ($intro)<p class="muted" style="margin:0 0 14px">{{ $intro }}</p>@endif
            {{ $slot }}
        </div>
    </div>
</div>
