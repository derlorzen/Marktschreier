@props(['active' => null])
@php $tabs = \App\Support\OpenTabs::products(); @endphp
@if ($tabs->isNotEmpty())
    <nav class="doc-tabs" aria-label="Geöffnete Artikel">
        <a class="doc-tab" href="{{ route('products.index') }}" wire:navigate @if ($active === null) aria-current="page" @endif>
            <span>Übersicht</span>
        </a>
        @foreach ($tabs as $tab)
            <span class="doc-tab" @if ($active === $tab->id) aria-current="page" @endif>
                <a href="{{ route('products.edit', $tab) }}" wire:navigate style="color:inherit;text-decoration:none;overflow:hidden;text-overflow:ellipsis" title="{{ $tab->title }}">
                    @if ($tab->push_status !== 'synced')<span aria-label="nicht übertragen" style="color:var(--warn)">●</span>@endif
                    {{ \Illuminate\Support\Str::limit($tab->title, 30) }}
                </a>
                <a class="close" href="{{ route('products.close', $tab) }}" wire:navigate aria-label="Reiter {{ $tab->title }} schließen">✕</a>
            </span>
        @endforeach
        @if ($tabs->count() > 1)
            <a class="doc-tab muted" href="{{ route('products.close-all') }}" wire:navigate style="margin-left:auto">Alle schließen</a>
        @endif
    </nav>
@endif
