<?php

namespace App\Http\Requests;

use App\Enums\TruckStatus;
use App\Models\Truck;
use App\Rules\UsableTruckType;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTruckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::FLEET_MANAGE) ?? false;
    }

    public function rules(): array
    {
        return [
            'plate_number' => ['required', 'string', 'max:32'],
            'type' => ['required', 'string', 'max:32', new UsableTruckType($this->user(), $this->currentType())],
            'capacity_tons' => ['required', 'numeric', 'min:0.1'],
            'trip_load_limit_tons' => ['required', 'numeric', 'min:0.1', 'lte:capacity_tons'],
            'volume_cbm' => ['nullable', 'numeric', 'min:0.01', 'max:9999'],
            'cargo_length_m' => ['nullable', 'numeric', 'min:0.01', 'max:50'],
            'cargo_width_m' => ['nullable', 'numeric', 'min:0.01', 'max:10'],
            'cargo_height_m' => ['nullable', 'numeric', 'min:0.01', 'max:10'],
            'axle_count' => ['nullable', 'integer', 'min:1', 'max:12'],
            'year' => ['nullable', 'integer', 'min:1980', 'max:2100'],
            'make' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(TruckStatus::class)],
            'assigned_driver_id' => ['nullable', 'integer', 'exists:users,id'],
            'insurance_expires_at' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('trip_load_limit_tons')) {
            return;
        }

        $truck = $this->route('truck');
        if ($truck instanceof Truck && $truck->trip_load_limit_tons !== null) {
            $this->merge([
                'trip_load_limit_tons' => $truck->trip_load_limit_tons,
            ]);

            return;
        }

        $capacity = $this->input('capacity_tons');
        $capacityTons = is_numeric($capacity) ? (float) $capacity : (float) Truck::DEFAULT_TRIP_LOAD_LIMIT_TONS;

        $this->merge([
            'trip_load_limit_tons' => Truck::defaultTripLoadLimit($capacityTons),
        ]);
    }

    public function messages(): array
    {
        return [
            'trip_load_limit_tons.lte' => 'The trip load limit cannot exceed the truck capacity.',
            'trip_load_limit_tons.min' => 'Enter a trip load limit of at least 0.1 tons.',
        ];
    }

    private function currentType(): ?string
    {
        $truck = $this->route('truck');
        $type = $truck?->type;

        return is_object($type) && property_exists($type, 'value') ? $type->value : $type;
    }
}
