<?php

namespace App\Rules;

use App\Models\User;
use App\Services\TruckTypeService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ShipmentTruckType implements ValidationRule
{
    public function __construct(
        private readonly ?User $user,
        private readonly ?string $currentCode = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('The vehicle type is required.');

            return;
        }

        $types = app(TruckTypeService::class);
        if ($types->findByCode($value) === null) {
            return;
        }

        if (! $types->isUsable($this->user, $value, $this->currentCode)) {
            $fail('The selected truck type is invalid.');
        }
    }
}
