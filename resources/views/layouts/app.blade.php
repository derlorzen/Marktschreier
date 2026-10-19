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
        ['route' => 'products.index', 'match' => 'products.*', 'label' => 'Artikel', 'icon' => 'M4 7h16M4 12h16M4 17h10'],
        ['route' => 'sync', 'match' => 'sync', 'label' => 'Abgleich', 'icon' => 'M4 12a8 8 0 0 1 14-5.3M20 12a8 8 0 0 1-14 5.3M18 3v4h-4M6 21v-4h4'],
        ['route' => 'settings', 'match' => 'settings', 'label' => 'Einstellungen', 'icon' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM19 12l2-1-1-3-2 .3-1.4-1.4.3-2-3-1-1 2h-2l-1-2-3 1 .3 2L5.3 9 3 8.7l-1 3 2 1v2l-2 1 1 3 2-.3 1.4 1.4-.3 2 3 1 1-2h2l1 2 3-1-.3-2 1.4-1.4 2 .3 1-3-2-1Z'],
    ];
    $pending = \App\Models\Product::whereIn('push_status', ['pending', 'error', 'conflict'])->count();
@endphp
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('products.index') }}" wire:navigate>
            <span class="brand-mark" aria-hidden="true">M</span> Marktschreier
        </a>
        <div class="nav-section">Stammdaten</div>
        @foreach ($nav as $item)
            <a class="nav-link" href="{{ route($item['route']) }}" wire:navigate @if (request()->routeIs($item['match'])) aria-current="page" @endif>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $item['icon'] }}"/></svg>
                {{ $item['label'] }}
                @if ($item['route'] === 'sync' && $pending)
                    <span class="count" title="Artikel mit offenen Änderungen">{{ $pending }}</span>
                @endif
            </a>
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
