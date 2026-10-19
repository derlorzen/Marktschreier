<?php

namespace App\Livewire\Products;

use App\Models\Channel;
use App\Models\ChangeLog;
use App\Models\ChannelContent;
use App\Models\MarketplaceConnection;
use App\Models\Product;
use App\Services\Content\AiWriter;
use App\Services\Marketplaces\Amazon\AmazonService;
use App\Services\Marketplaces\Ebay\EbayService;
use Livewire\Component;

/** Reiter „Texte je Kanal“: eigener Titel, Stichpunkte, Suchbegriffe, Beschreibung. */
class Contents extends Component
{
    public Product $product;

    public string $channelKey = 'amazon';

    public array $form = ['title' => '', 'bullets' => ['', '', '', '', ''], 'search_terms' => '', 'description_html' => ''];

    public bool $fromAi = false;

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(Product $product): void
    {
        $this->product = $product;
        $first = Channel::active()->where('key', '!=', Channel::SHOPIFY)->first();
        $this->channelKey = $first?->key ?? 'amazon';
        $this->load();
    }

    private function channel(): Channel
    {
        return Channel::where('key', $this->channelKey)->firstOrFail();
    }

    private function load(): void
    {
        $c = $this->product->fresh('channelContents')->contentFor($this->channel());
        $bullets = array_pad(array_slice($c->bullets ?? [], 0, 5), 5, '');
        $this->form = ['title' => (string) $c->title, 'bullets' => $bullets, 'search_terms' => (string) $c->search_terms, 'description_html' => (string) $c->description_html];
        $this->fromAi = false;
    }

    public function updatedChannelKey(): void
    {
        $this->load();
        $this->reset('notice', 'error');
    }

    public function save(): ChannelContent
    {
        $limits = ChannelContent::LIMITS[$this->channelKey] ?? ['title' => 255];
        $this->validate([
            'form.title' => ['nullable', 'string', 'max:'.$limits['title']],
            'form.bullets.*' => ['nullable', 'string', 'max:'.($limits['bullet'] ?? 500)],
            'form.search_terms' => ['nullable', 'string', 'max:'.($limits['search_terms'] ?? 1000)],
            'form.description_html' => ['nullable', 'string', 'max:65000'],
        ], [
            'form.title.max' => 'Der Titel darf auf diesem Kanal höchstens :max Zeichen haben.',
            'form.bullets.*.max' => 'Ein Stichpunkt darf höchstens :max Zeichen haben.',
            'form.search_terms.max' => 'Suchbegriffe höchstens :max Zeichen.',
        ]);
        $channel = $this->channel();
        $content = ChannelContent::firstOrNew(['product_id' => $this->product->id, 'channel_id' => $channel->id]);
        $before = $content->exists ? $content->only(['title', 'search_terms']) : [];
        $content->fill([
            'title' => trim($this->form['title']) ?: null,
            'bullets' => array_values(array_filter(array_map('trim', $this->form['bullets']))) ?: null,
            'search_terms' => trim($this->form['search_terms']) ?: null,
            'description_html' => trim($this->form['description_html']) ?: null,
            'source' => $this->fromAi ? 'ai' : 'manual',
        ])->save();
        if (($before['title'] ?? null) !== $content->title) {
            ChangeLog::record($this->product, 'Titel '.$channel->name, $before['title'] ?? null, $content->title, 'user', null, auth()->id());
        }
        $this->notice = 'Texte für '.$channel->name.' gespeichert.';
        $this->error = null;

        return $content;
    }

    public function suggest(AiWriter $ai): void
    {
        try {
            $s = $ai->suggest($this->product->fresh(), $this->channel());
            $this->form = ['title' => $s['title'], 'bullets' => array_pad(array_slice($s['bullets'], 0, 5), 5, ''), 'search_terms' => $s['search_terms'], 'description_html' => $s['description_html']];
            $this->fromAi = true;
            $this->notice = 'Vorschlag eingefügt. Bitte prüfen und dann speichern.';
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function push(AmazonService $amazon, EbayService $ebay): void
    {
        $content = $this->save();
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $content->setRelation('product', $this->product);
        try {
            $r = match ($this->channelKey) {
                'amazon' => $amazon->pushContent($this->product, $content),
                'ebay' => $ebay->pushContent($this->product, $content),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }
        if ($r === null) {
            $this->error = 'Für diesen Kanal gibt es noch keine Übertragung.';

            return;
        }
        $content->update(['pushed_at' => $r['ok'] ? now() : $content->pushed_at, 'push_error' => $r['errors'] ? implode(' ', $r['errors']) : null]);
        if ($r['ok'] === 0 && $r['failed'] === 0) {
            $this->error = 'Auf '.$this->channel()->name.' gibt es zu diesem Artikel noch kein Angebot.';
        } else {
            $this->notice = "{$r['ok']} Angebote aktualisiert.".($r['failed'] ? " {$r['failed']} abgelehnt." : '');
            $this->error = $r['errors'] ? implode(' ', $r['errors']) : null;
        }
    }

    public function render(AiWriter $ai)
    {
        $channel = $this->channel();
        $content = $this->product->fresh('channelContents')->contentFor($channel);

        return view('livewire.products.contents', [
            'channels' => Channel::active()->where('key', '!=', Channel::SHOPIFY)->get(),
            'limits' => ChannelContent::LIMITS[$this->channelKey] ?? ['title' => 255],
            'aiReady' => $ai->configured(),
            'canPush' => in_array($this->channelKey, ['amazon', 'ebay'], true) && MarketplaceConnection::for($this->channelKey)->isConnected(),
            'content' => $content,
            'channel' => $channel,
        ]);
    }
}
