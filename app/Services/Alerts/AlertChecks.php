<?php

namespace App\Services\Alerts;

use App\Models\Listing;
use App\Services\Opportunities\Opportunity;
use App\Services\Opportunities\OpportunityFinder;

/**
 * Prüft regelmäßig, ob es etwas zu melden gibt, und schließt Hinweise, die sich erledigt haben.
 * Weitere Prüfungen (Buy Box, Überverkauf, Releases) hängen sich über register() an.
 */
class AlertChecks
{
    /** @var array<string, callable(AlertService): void> */
    private static array $extra = [];

    public static function register(string $name, callable $check): void
    {
        self::$extra[$name] = $check;
    }

    public function __construct(private AlertService $alerts, private OpportunityFinder $finder)
    {
    }

    public function run(): void
    {
        $opps = $this->finder->all(includeDismissed: true);

        // Ausverkauft oder in wenigen Tagen leer
        $keys = [];
        foreach ($opps->where('type', 'stockout') as $o) {
            $key = 'stockout:'.$o->variant?->id;
            $keys[] = $key;
            $this->alerts->raise($key, 'stockout', $o->variant?->stock <= 0 ? 'bad' : 'warn', $o->title, $o->text, $o->actionUrl);
        }
        $this->alerts->resolveMissing('stockout', $keys);

        // Verlust je Verkauf
        $keys = [];
        foreach ($opps->where('type', 'low_profit')->filter(fn (Opportunity $o) => str_contains($o->title, 'Verlust')) as $o) {
            $key = 'loss:'.$o->variant?->id.':'.$o->channel?->id;
            $keys[] = $key;
            $this->alerts->raise($key, 'loss', 'warn', $o->title, $o->text, $o->actionUrl);
        }
        $this->alerts->resolveMissing('loss', $keys);

        // Abgelehnte oder fehlerhafte Angebote
        $keys = [];
        foreach (Listing::with('variant.product', 'channel')->where('status', 'error')->get()->groupBy(fn ($l) => $l->variant->product_id.':'.$l->channel_id) as $group) {
            $l = $group->first();
            $key = 'listing:'.$l->variant->product_id.':'.$l->channel_id;
            $keys[] = $key;
            $this->alerts->raise($key, 'listing_error', 'warn', "{$l->variant->product->title}: Problem auf {$l->channel->name}", $l->last_error, '/artikel/'.$l->variant->product_id.'?tab=marktplaetze');
        }
        $this->alerts->resolveMissing('listing_error', $keys);

        foreach (self::$extra as $check) {
            $check($this->alerts);
        }
    }
}
