<?php

namespace App\Services\Pricing;

use App\Models\Channel;
use App\Models\PriceRule;
use App\Models\Setting;

/**
 * Rechenkern: Gewinn je Verkauf und Preis aus dem Netto-Einkaufspreis.
 *
 * Gewinn = Preis / (1 + MwSt) - Preis * Gebühr% - feste Gebühr - Versand - EK netto
 * Zielmarge ist der Gewinn in Prozent vom Nettopreis.
 */
class PriceCalculator
{
    public function __construct(private ?float $vatRate = null)
    {
        $this->vatRate ??= (float) Setting::get('billbee.vat_rate', 19);
    }

    public function vat(): float
    {
        return $this->vatRate;
    }

    /** Kosten je Verkauf, die nicht vom Preis abhängen. */
    public function fixedCosts(Channel $channel): float
    {
        return (float) $channel->fee_fixed + (float) $channel->shipping_cost;
    }

    public function profit(float $grossPrice, ?float $costNet, Channel $channel): ?float
    {
        if ($costNet === null) {
            return null;
        }
        $net = $grossPrice / (1 + $this->vatRate / 100);
        $fees = $grossPrice * (float) $channel->fee_percent / 100;

        return round($net - $fees - $this->fixedCosts($channel) - $costNet, 2);
    }

    /** Gewinn in % vom Nettopreis. */
    public function margin(float $grossPrice, ?float $costNet, Channel $channel): ?float
    {
        $profit = $this->profit($grossPrice, $costNet, $channel);
        $net = $grossPrice / (1 + $this->vatRate / 100);

        return $profit === null || $net <= 0 ? null : round($profit / $net * 100, 1);
    }

    /**
     * Preis nach Regelwerk. Null, wenn EK fehlt oder das Regelwerk den Kanal nicht kennt.
     */
    public function calculate(?float $costNet, Channel $channel, PriceRule $rule): ?float
    {
        $line = $rule->lineFor($channel);
        if ($costNet === null || $costNet <= 0 || ! $line) {
            return null;
        }
        $vatFactor = 1 + $this->vatRate / 100;
        $feeShare = (float) $channel->fee_percent / 100;
        $fixed = $this->fixedCosts($channel);

        $denominator = (1 - (float) $line->target_margin / 100) / $vatFactor - $feeShare;
        $price = $denominator > 0.01 ? ($costNet + $fixed) / $denominator : null;

        // Mindestgewinn je Verkauf hat Vorrang vor der Zielmarge.
        $minDenominator = 1 / $vatFactor - $feeShare;
        if ($minDenominator <= 0.01) {
            return null;
        }
        $minPrice = ($costNet + $fixed + (float) $rule->min_profit) / $minDenominator;
        if ($price === null || $price < $minPrice) {
            $price = $minPrice;
        }

        return self::roundToEnding($price, (string) $line->ending);
    }

    /** Kleinster Preis mit der gewünschten Endung, der nicht unter dem Rohpreis liegt. */
    public static function roundToEnding(float $price, string $ending): float
    {
        $cents = ['99' => 0.99, '95' => 0.95, '90' => 0.90, '00' => 0.0][$ending] ?? 0.99;
        $candidate = floor($price) + $cents;
        if ($candidate < $price - 0.0001) {
            $candidate += 1;
        }

        return round(max($candidate, 0.99), 2);
    }
}
