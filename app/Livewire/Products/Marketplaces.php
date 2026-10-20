<?php

namespace App\Livewire\Products;

use App\Models\Channel;
use App\Models\Listing;
use App\Models\MarketplaceConnection;
use App\Models\Product;
use App\Models\Variant;
use App\Services\Marketplaces\Amazon\AmazonService;
use App\Services\Marketplaces\Ebay\EbayService;
use App\Services\Marketplaces\MarketplaceManager;
use Livewire\Component;

/** Reiter „Marktplätze“ im Artikel: Status je Kanal, Preise übertragen, neue Angebote anlegen. */
class Marketplaces extends Component
{
    public Product $product;

    public ?string $notice = null;

    public ?string $error = null;

    // eBay: neues Angebot
    public bool $ebayForm = false;

    public string $ebayQuery = '';

    public array $ebayCategories = [];

    public string $ebayCategoryId = '';

    public array $ebayAspects = [];

    public array $ebaySpecifics = [];

    public ?array $ebayCheck = null;

    // Amazon: Treffer je Variante [variant_id => list]
    public array $amazonMatches = [];

    /** Variante, für die gerade ein neues Amazon-Produkt angelegt wird */
    public ?int $newProductFor = null;

    public array $amazonChoice = [];

    #[\Livewire\Attributes\On('close-amazon-new')]
    public function closeNewProduct(): void
    {
        $this->newProductFor = null;
    }

    public function mount(Product $product): void
    {
        $this->product = $product;
        $this->ebayQuery = $product->title;
    }

    private function fail(\Throwable $e): void
    {
        $this->error = $e->getMessage();
        $this->notice = null;
    }

    public function pushPrices(string $provider, MarketplaceManager $manager): void
    {
        try {
            $r = $manager->pushPrices($provider, [$this->product->id]);
            $this->error = $r['failed'] ? "{$r['failed']} Preise wurden abgelehnt, siehe Hinweise in der Tabelle." : null;
            $this->notice = $r['ok'] ? "{$r['ok']} Preise übertragen." : ($r['failed'] ? null : 'Keine offenen Preise.');
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    // ---- eBay ----

    public function ebaySearch(EbayService $ebay): void
    {
        try {
            $this->ebayCategories = $ebay->suggestCategories($this->ebayQuery);
            $this->error = $this->ebayCategories ? null : 'Keine passende Kategorie gefunden, Suchbegriff ändern.';
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    public function ebayPickCategory(string $id, EbayService $ebay): void
    {
        try {
            $this->ebayCategoryId = $id;
            $this->ebayAspects = $ebay->aspects($id);
            $this->product->load('attributeValues', 'variants');
            $this->ebaySpecifics = $ebay->prefillSpecifics($this->product, $this->ebayAspects);
            $this->ebayCheck = null;
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    private function missingRequired(): array
    {
        return collect($this->ebayAspects)->where('required', true)->pluck('name')
            ->filter(fn ($n) => trim((string) ($this->ebaySpecifics[$n] ?? '')) === '')->values()->all();
    }

    public function ebayVerify(EbayService $ebay): void
    {
        if ($missing = $this->missingRequired()) {
            $this->error = 'Pflichtangaben fehlen: '.implode(', ', $missing).'.';

            return;
        }
        try {
            $r = $ebay->createListing($this->product->fresh(), $this->ebayCategoryId, $this->ebaySpecifics, verifyOnly: true);
            $this->ebayCheck = $r;
            $this->error = null;
            $this->notice = 'eBay hat das Angebot geprüft'.($r['fees'] !== null ? ', Einstellgebühren '.number_format($r['fees'], 2, ',', '.').' €' : '').'.';
        } catch (\Throwable $e) {
            $this->ebayCheck = null;
            $this->fail($e);
        }
    }

    public function ebayPublish(EbayService $ebay): void
    {
        if (! $this->ebayCheck) {
            $this->error = 'Bitte zuerst prüfen lassen.';

            return;
        }
        try {
            // Merkmale für das nächste Mal am Artikel merken.
            foreach ($this->ebaySpecifics as $name => $value) {
                if (trim((string) $value) !== '' && ! in_array(mb_strtolower($name), ['marke', 'brand'], true)) {
                    $this->product->attributeValues()->firstOrCreate(['key' => $name], ['value' => trim($value)]);
                }
            }
            $r = $ebay->createListing($this->product->fresh(), $this->ebayCategoryId, $this->ebaySpecifics);
            $this->reset('ebayForm', 'ebayCategories', 'ebayCategoryId', 'ebayAspects', 'ebaySpecifics', 'ebayCheck');
            $this->notice = 'Bei eBay eingestellt, Artikelnummer '.$r['item_id'].'. Den Bestand übernimmt ab jetzt Billbee, sofern das eBay-Konto dort verbunden ist.';
            $this->error = null;
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    // ---- Amazon ----

    public function amazonLookup(int $variantId, AmazonService $amazon): void
    {
        $variant = $this->product->variants()->findOrFail($variantId);
        if (blank($variant->barcode)) {
            $this->error = 'Ohne GTIN lässt sich das Produkt bei Amazon nicht finden. Lege es als neues Produkt an (Befreiung von der GTIN in Seller Central beantragen).';
            $this->newProductFor = $variantId;

            return;
        }
        try {
            $this->amazonMatches[$variantId] = $amazon->findByGtin($variant->barcode);
            $this->amazonChoice[$variantId] = $this->amazonMatches[$variantId][0]['asin'] ?? '';
            $this->error = null;
            if (! $this->amazonMatches[$variantId]) {
                $this->notice = 'Zu dieser GTIN gibt es bei Amazon noch kein Produkt. Du kannst es hier neu anlegen.';
                $this->newProductFor = $variantId;
            }
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    public function amazonCreate(int $variantId, AmazonService $amazon): void
    {
        $variant = $this->product->variants()->findOrFail($variantId);
        $asin = $this->amazonChoice[$variantId] ?? '';
        if (! preg_match('/^[A-Z0-9]{10}$/', $asin)) {
            $this->error = 'Bitte ein Produkt auswählen.';

            return;
        }
        try {
            $listing = $amazon->createOffer($variant, $asin, (string) $amazon->client()->connection()->setting('fulfillment', 'FBM'));
            unset($this->amazonMatches[$variantId], $this->amazonChoice[$variantId]);
            if ($listing->status === 'error') {
                $this->error = 'Amazon hat das Angebot abgelehnt: '.$listing->last_error;
            } else {
                $this->notice = "Angebot für {$variant->label()} an Amazon übergeben. Bis es kaufbar ist, kann es einige Minuten dauern.";
                $this->error = null;
            }
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    public function render(MarketplaceManager $manager)
    {
        $variants = $this->product->variants()->with(['listings', 'channelPrices'])->get();
        $providers = [];
        foreach (MarketplaceManager::PROVIDERS as $provider) {
            $channel = Channel::where('key', $provider)->first();
            if (! $channel) {
                continue;
            }
            $connection = MarketplaceConnection::for($provider);
            $rows = $variants->map(function (Variant $v) use ($channel) {
                $listing = $v->listings->firstWhere('channel_id', $channel->id);
                if ($listing) {
                    $listing->setRelation('variant', $v)->setRelation('channel', $channel);
                }

                return ['variant' => $v, 'listing' => $listing, 'price' => $v->priceOn($channel)];
            });

            $providers[$provider] = [
                'channel' => $channel,
                'connected' => $connection->isConnected(),
                'checked' => $connection->listings_checked_at,
                'rows' => $rows,
                'pending' => $rows->filter(fn ($r) => $r['listing']?->pricePending($r['price']))->count(),
                'hasListing' => $rows->contains(fn ($r) => $r['listing']?->external_id && $r['listing']->status !== 'inactive'),
            ];
        }

        return view('livewire.products.marketplaces', ['providers' => $providers]);
    }
}
