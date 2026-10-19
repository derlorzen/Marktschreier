<x-settings-shell active="settings.schedule" title="Abgleich und Zeitplan" intro="Wie oft Marktschreier automatisch Daten holt. Übertragen zu Shopify und zu den Marktplätzen passiert nie automatisch, nur auf deinen Klick.">
    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:16px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif
    <form class="card" wire:submit="save">
        <div class="table-wrap">
            <table class="list">
                <thead><tr><th>Automatisch</th><th>Was</th><th>Wie oft</th></tr></thead>
                <tbody>
                @foreach (\App\Livewire\Settings\Schedule::JOBS as $key => $def)
                    <tr>
                        <td style="width:110px"><input type="checkbox" class="check" wire:model="jobs.{{ $key }}.enabled" aria-label="{{ $def['label'] }} automatisch"></td>
                        <td>{{ $def['label'] }}</td>
                        <td style="width:200px">
                            <select class="input input-sm" wire:model="jobs.{{ $key }}.minutes" aria-label="Intervall {{ $def['label'] }}">
                                @foreach ($def['options'] as $m)<option value="{{ $m }}">{{ \App\Livewire\Settings\Schedule::label($m) }}</option>@endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-pad row"><button class="btn btn-primary" type="submit">Speichern</button>
            <a class="btn" href="{{ route('sync') }}" wire:navigate>Zum Protokoll</a></div>
    </form>
    <p class="muted small">Der Shopify-Abgleich startet erst nach dem ersten vollständigen Import, eBay und Amazon erst, wenn sie verbunden sind.</p>
</x-settings-shell>
