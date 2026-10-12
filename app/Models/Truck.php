<?php

namespace App\Models;

use App\Enums\TruckStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'organization_id',
    'plate_number',
    'type',
    'capacity_tons',
    'trip_load_limit_tons',
    'volume_cbm',
    'cargo_length_m',
    'cargo_width_m',
    'cargo_height_m',
    'axle_count',
    'year',
    'make',
    'model',
    'status',
    'assigned_driver_id',
    'insurance_expires_at',
])]
class Truck extends Model
{
    public const DEFAULT_TRIP_LOAD_LIMIT_TONS = 25;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'trip_load_limit_tons' => self::DEFAULT_TRIP_LOAD_LIMIT_TONS,
    ];

    protected static function booted(): void
    {
        static::creating(function (Truck $truck): void {
            $capacity = (float) $truck->capacity_tons;
            if ($capacity <= 0) {
                return;
            }

            $limit = (float) ($truck->trip_load_limit_tons ?? self::DEFAULT_TRIP_LOAD_LIMIT_TONS);
            if ($limit === (float) self::DEFAULT_TRIP_LOAD_LIMIT_TONS && $capacity < self::DEFAULT_TRIP_LOAD_LIMIT_TONS) {
                $truck->trip_load_limit_tons = $capacity;
            }
        });
    }

    public static function defaultTripLoadLimit(float $capacityTons): float
    {
        if ($capacityTons <= 0) {
            return (float) self::DEFAULT_TRIP_LOAD_LIMIT_TONS;
        }

        return min((float) self::DEFAULT_TRIP_LOAD_LIMIT_TONS, $capacityTons);
    }

    public function tripLoadTons(): float
    {
        $capacity = (float) $this->capacity_tons;
        $limit = (float) ($this->trip_load_limit_tons ?? self::DEFAULT_TRIP_LOAD_LIMIT_TONS);

        if ($capacity <= 0) {
            return max(0, $limit);
        }

        if ($limit <= 0) {
            return $capacity;
        }

        return min($capacity, $limit);
    }

    protected function casts(): array
    {
        return [
            'status' => TruckStatus::class,
            'capacity_tons' => 'decimal:2',
            'trip_load_limit_tons' => 'decimal:2',
            'volume_cbm' => 'decimal:2',
            'cargo_length_m' => 'decimal:2',
            'cargo_width_m' => 'decimal:2',
            'cargo_height_m' => 'decimal:2',
            'axle_count' => 'integer',
            'insurance_expires_at' => 'date',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isAvailable(): bool
    {
        return $this->status === TruckStatus::Available;
    }
}
