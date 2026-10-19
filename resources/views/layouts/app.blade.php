<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}Marktschreier</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    @livewireStyles
</head>
<body>
@php
    $nav = [
        '' => [
            ['route' => 'dashboard', 'match' => 'dashboard', 'label' => 'Start', 'icon' => 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10'],
            ['route' => 'alerts', 'match' => 'alerts', 'label' => 'Hinweise', 'icon' => 'M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.9 1.9 0 0 0 3.4 0'],
            ['route' => 'opportunities', 'match' => 'opportunities', 'label' => 'Chancen', 'icon' => 'M12 3l2.6 5.6 6.1.7-4.5 4.2 1.2 6L12 16.6 6.6 19.5l1.2-6L3.3 9.3l6.1-.7L12 3z'],
        ],
        'Verkaufen' => [
            ['route' => 'ads', 'match' => 'ads', 'label' => 'Werbung', 'icon' => 'M3 11l15-6v14L3 13v-2zM7 13v5a2 2 0 0 0 4 0v-3'],
            ['route' => 'price-watches', 'match' => 'price-watches', 'label' => 'Preiswächter', 'icon' => 'M12 2l7 4v6c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-4zM9 12l2 2 4-4'],
        ],
        'Sortiment' => [
            ['route' => 'products.index', 'match' => 'products.*', 'label' => 'Artikel', 'icon' => 'M4 7h16M4 12h16M4 17h10'],
            ['route' => 'stock', 'match' => 'stock', 'label' => 'Bestand', 'icon' => 'M3 7l9-4 9 4-9 4-9-4zM3 7v10l9 4 9-4V7M12 11v10'],
            ['route' => 'pricing', 'match' => 'pricing', 'label' => 'Preisregeln', 'icon' => 'M7 3h10a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zM9 7h6M9 11h1M12 11h1M15 11h0M9 15h1M12 15h1M9 18h1M12 18h1M15 15v3'],
        ],
        'System' => [
            ['route' => 'sync', 'match' => 'sync', 'label' => 'Abgleich', 'icon' => 'M4 12a8 8 0 0 1 14-5.3M20 12a8 8 0 0 1-14 5.3M18 3v4h-4M6 21v-4h4'],
            ['route' => auth()->user()?->isAdmin() ? 'settings' : 'settings.account', 'match' => 'settings*', 'label' => auth()->user()?->isAdmin() ? 'Einstellungen' : 'Mein Konto', 'icon' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM19 12l2-1-1-3-2 .3-1.4-1.4.3-2-3-1-1 2h-2l-1-2-3 1 .3 2L5.3 9 3 8.7l-1 3 2 1v2l-2 1 1 3 2-.3 1.4 1.4-.3 2 3 1 1-2h2l1 2 3-1-.3-2 1.4-1.4 2 .3 1-3-2-1Z'],
        ],
    ];
    $pending = \App\Models\Product::whereIn('push_status', ['pending', 'error', 'conflict'])->count();
    $unreadAlerts = auth()->check() ? \App\Models\Alert::unreadFor(auth()->user())->count() : 0;
    $badAlerts = $unreadAlerts ? \App\Models\Alert::unreadFor(auth()->user())->where('level', 'bad')->exists() : false;
@endphp
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('dashboard') }}" wire:navigate>
            <span class="brand-mark" aria-hidden="true">M</span>
            <span>Marktschreier @if ($company = \App\Models\Setting::get('general.company'))<span class="brand-sub">{{ $company }}</span>@endif</span>
        </a>
        @foreach ($nav as $section => $items)
            @if ($section !== '')<div class="nav-section">{{ $section }}</div>@endif
            @foreach ($items as $item)
                <a class="nav-link" href="{{ route($item['route']) }}" wire:navigate @if (request()->routeIs($item['match'])) aria-current="page" @endif>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $item['icon'] }}"/></svg>
                    {{ $item['label'] }}
                    @if ($item['route'] === 'alerts' && $unreadAlerts)
                        <span @class(['count', 'count-bad' => $badAlerts]) title="Ungelesene Hinweise">{{ $unreadAlerts }}</span>
                    @endif
                    @if ($item['route'] === 'sync' && $pending)
                        <span class="count" title="Artikel mit offenen Änderungen">{{ $pending }}</span>
                    @endif
                </a>
            @endforeach
        @endforeach
        <div class="sidebar-foot">
            {{ auth()->user()?->name }}<br>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="linkbtn" type="submit">Abmelden</button></form>
        </div>
    </aside>
    <main class="main">
        {{ $slot }}
    </main>
</div>
@if (session('flash'))
    <div class="flash" role="status" x-data x-init="setTimeout(() => $el.remove(), 5000)">{{ session('flash') }}</div>
@endif
@livewireScripts
</body>
</html>
