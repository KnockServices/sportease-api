<?php

namespace App\Services;

use App\Models\Court;
use App\Models\Family;

/**
 * Court reservation pricing engine.
 *
 * Each court has duration tiers (e.g. 60 min $80, 90 min $110, 120 min $140).
 *  - An exact tier match uses that tier's price.
 *  - Shorter than the smallest tier → the smallest tier is the minimum charge.
 *  - Longer bookings are filled with the largest tiers that fit; any leftover minutes
 *    are charged pro-rata at the court's hourly rate.
 *  - A court with no tiers is charged pro-rata at its hourly rate.
 * The family discount (or a staff override) is then applied on top.
 */
class Pricing
{
    /** @return array{duration_min:int, base:float, method:string, breakdown:array<int,array{label:string,amount:float}>, note:string} */
    public static function courtPrice(Court $court, int $minutes): array
    {
        $minutes = max(0, $minutes);
        $tiers = $court->rates()->get(['duration_min', 'price'])->sortByDesc('duration_min')->values();
        $rate = (float) $court->hourly_rate;
        $lines = [];

        if ($tiers->isEmpty()) {
            $lines[] = ['label' => "{$minutes} min @ \$".self::fmt($rate).'/h', 'amount' => round($rate * $minutes / 60, 2)];
            $method = 'hourly';
        } elseif ($exact = $tiers->firstWhere('duration_min', $minutes)) {
            $lines[] = ['label' => "{$minutes} min rate", 'amount' => (float) $exact->price];
            $method = 'tier';
        } elseif ($minutes < $tiers->last()->duration_min) {
            $min = $tiers->last();
            $lines[] = ['label' => "Minimum booking {$min->duration_min} min", 'amount' => (float) $min->price];
            $method = 'minimum';
        } else {
            $left = $minutes;
            foreach ($tiers as $tier) {
                while ($left >= $tier->duration_min) {
                    $lines[] = ['label' => "{$tier->duration_min} min rate", 'amount' => (float) $tier->price];
                    $left -= $tier->duration_min;
                }
            }
            if ($left > 0) {
                $lines[] = ['label' => "{$left} extra min @ \$".self::fmt($rate).'/h', 'amount' => round($rate * $left / 60, 2)];
            }
            $method = 'combined';
        }

        // Merge repeated tiers: "2 × 120 min rate".
        $merged = collect($lines)->groupBy('label')->map(fn ($g, $label) => [
            'label' => $g->count() > 1 ? "{$g->count()} × {$label}" : $label,
            'amount' => round($g->sum('amount'), 2),
        ])->values()->all();
        $base = round(collect($merged)->sum('amount'), 2);

        return [
            'duration_min' => $minutes, 'base' => $base, 'method' => $method, 'breakdown' => $merged,
            'note' => collect($merged)->map(fn ($l) => $l['label'])->implode(' + '),
        ];
    }

    /** Full quote including the family discount (auto) or a staff override. */
    public static function quote(Court $court, int $minutes, ?Family $family = null, ?float $discountOverride = null, ?float $priceOverride = null): array
    {
        $engine = self::courtPrice($court, $minutes);
        $base = $priceOverride ?? $engine['base'];
        $familyPct = $family && $family->code !== 'WALK-IN' ? (float) $family->discount_percent : 0.0;
        $pct = $discountOverride ?? $familyPct;
        $discount = round($base * $pct / 100, 2);

        return $engine + [
            'engine_price' => $engine['base'],
            'price' => round($base, 2),
            'price_overridden' => $priceOverride !== null,
            'family_discount_percent' => $familyPct,
            'discount_percent' => $pct,
            'discount_overridden' => $discountOverride !== null,
            'discount_amount' => $discount,
            'total' => round($base - $discount, 2),
            'family' => $family ? ['id' => $family->id, 'code' => $family->code, 'name' => $family->name] : null,
        ];
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
