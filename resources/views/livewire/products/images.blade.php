<div>
    @if ($notice)<div class="notice notice-ok row" style="margin-bottom:12px"><span>{{ $notice }}</span><button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>@endif
    @if ($error)<div class="notice notice-bad" style="margin-bottom:12px">{{ $error }}</div>@endif

    @if (! $product->shopify_id)
        <div class="empty">Bilder lassen sich hochladen, sobald der Artikel in Shopify angelegt ist.</div>
    @else
        <form class="row" style="margin-bottom:16px" wire:submit="upload">
            <input type="file" wire:model="uploads" multiple accept="image/jpeg,image/png,image/webp,image/gif" aria-label="Bilder auswählen">
            <button class="btn btn-primary btn-sm" type="submit" wire:loading.attr="disabled" @disabled(! $uploads)>Hochladen <span class="spin" wire:loading wire:target="upload,uploads"></span></button>
            @error('uploads') <span class="error">{{ $message }}</span> @enderror
            @error('uploads.*') <span class="error">{{ $message }}</span> @enderror
        </form>
        @if ($images->isEmpty())
            <div class="empty">Noch keine Bilder.</div>
        @else
            <div class="gallery">
                @foreach ($images as $i => $image)
                    <figure style="margin:0" wire:key="img{{ $image->id }}">
                        <a href="{{ $image->url }}" target="_blank" rel="noopener"><img src="{{ $image->sized(400) }}" alt="{{ $image->alt }}" loading="lazy"></a>
                        <figcaption class="stack" style="margin-top:6px">
                            <span class="small muted">{{ $i === 0 ? 'Hauptbild' : ($i + 1).'. Bild' }}</span>
                            <div class="row" style="gap:4px">
                                <input class="input input-sm" style="flex:1;min-width:0" wire:model="alts.{{ $image->id }}" placeholder="Alt-Text" aria-label="Alt-Text Bild {{ $i + 1 }}">
                                <button class="btn btn-sm" wire:click="saveAlt({{ $image->id }})" title="Alt-Text speichern">OK</button>
                            </div>
                            <div class="row" style="gap:4px">
                                <button class="btn btn-sm" wire:click="move({{ $image->id }}, -1)" @disabled($i === 0) aria-label="Nach vorne">‹</button>
                                <button class="btn btn-sm" wire:click="move({{ $image->id }}, 1)" @disabled($loop->last) aria-label="Nach hinten">›</button>
                                <button class="btn btn-sm" style="margin-left:auto" wire:click="delete({{ $image->id }})" wire:confirm="Bild aus Shopify entfernen?">Entfernen</button>
                            </div>
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        @endif
        <p class="muted small">Das erste Bild ist das Hauptbild in Shopify und auf eBay. Alt-Texte helfen bei Google und Barrierefreiheit.</p>
    @endif
</div>
