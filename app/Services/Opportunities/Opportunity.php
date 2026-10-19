<?php

namespace App\Services\Opportunities;

use App\Models\Channel;
use App\Models\Product;
use App\Models\Variant;

/** Eine Chance mit Begründung, Schätzwert und nächstem Schritt. */
final class Opportunity
{
    public const FREE = 'free';
    public const ADS = 'ads';

    public function __construct(
        public string $type,
        public string $kind,
        public string $title,
        public string $text,
        public Product $product,
        public ?Variant $variant = null,
        public ?Channel $channel = null,
        /** geschätzter zusätzlicher bzw. geretteter Gewinn im Monat */
        public ?float $monthlyValue = null,
        /** Sortierhilfe, wenn es keinen Euro-Wert gibt */
        public float $weight = 0,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        /** zusätzliche Kennzahlen für die Anzeige */
        public array $facts = [],
    ) {
    }

    public function key(): string
    {
        return implode(':', [$this->type, $this->product->id, $this->variant?->id ?? '-', $this->channel?->id ?? '-']);
    }

    public function sortValue(): float
    {
        return ($this->monthlyValue ?? 0) * 10 + $this->weight;
    }

    public static function typeLabel(string $type): string
    {
        return [
            'missing_channel' => 'Fehlt auf einem Kanal',
            'listing_error' => 'Problem mit Angebot',
            'shop_inactive' => 'Im Shop nicht aktiv',
            'incomplete' => 'Daten unvollständig',
            'low_profit' => 'Zu wenig Gewinn',
            'stockout' => 'Bald ausverkauft',
            'slow_mover' => 'Liegt auf Lager',
            'ad_candidate' => 'Werbung lohnt sich',
        ][$type] ?? $type;
    }
}
