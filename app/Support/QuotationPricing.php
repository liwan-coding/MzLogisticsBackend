<?php

namespace App\Support;

use App\Enums\PricingBasis;
use Illuminate\Validation\ValidationException;

final class QuotationPricing
{
    /**
     * Trip pricing multiplies one trip by the trips that will be dispatched.
     * Ton pricing multiplies one ton by the shipment weight stored on the request.
     * Additional costs are added to that transport amount to produce the amount due.
     *
     * @param  array<string, mixed>  $payload
     * @return array{price_per_trip: float|null, price_per_ton: float|null, pricing_basis: PricingBasis|null, transport_price: float, additional_costs: float, total_price: float, trip_count: int}
     */
    public static function resolve(array $payload, ?float $weightTons = null): array
    {
        $tripCount = max((int) $payload['truck_count'], (int) $payload['trip_count']);
        $perTrip = self::amount($payload['price_per_trip'] ?? null);
        $perTon = self::amount($payload['price_per_ton'] ?? null);
        $additional = self::amount($payload['additional_costs'] ?? null) ?? 0.0;

        if ($perTrip !== null && $perTon !== null) {
            throw ValidationException::withMessages([
                'price_per_ton' => ['Choose either a price per trip or a price per ton.'],
            ]);
        }

        if ($perTon !== null) {
            if ($weightTons === null || $weightTons <= 0) {
                throw ValidationException::withMessages([
                    'price_per_ton' => ['This shipment has no weight, so it cannot be priced per ton.'],
                ]);
            }

            return self::priced(null, $perTon, PricingBasis::Ton, round($perTon * $weightTons, 3), $additional, $tripCount);
        }

        if ($perTrip !== null) {
            return self::priced($perTrip, null, PricingBasis::Trip, round($perTrip * $tripCount, 3), $additional, $tripCount);
        }

        return self::priced(null, null, null, round((float) $payload['total_price'], 3), $additional, $tripCount);
    }

    /**
     * @return array{price_per_trip: float|null, price_per_ton: float|null, pricing_basis: PricingBasis|null, transport_price: float, additional_costs: float, total_price: float, trip_count: int}
     */
    private static function priced(
        ?float $perTrip,
        ?float $perTon,
        ?PricingBasis $basis,
        float $transport,
        float $additional,
        int $tripCount,
    ): array {
        return [
            'price_per_trip' => $perTrip,
            'price_per_ton' => $perTon,
            'pricing_basis' => $basis,
            'transport_price' => $transport,
            'additional_costs' => $additional,
            'total_price' => round($transport + $additional, 3),
            'trip_count' => $tripCount,
        ];
    }

    private static function amount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 3);
    }
}
