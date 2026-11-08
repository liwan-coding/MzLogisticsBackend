<?php

namespace App\Http\Resources;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->enumValue($this->type),
            'account_type' => $this->enumValue($this->account_type),
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'commercial_register' => $this->commercial_register,
            'tax_number' => $this->tax_number,
            'email' => $this->email,
            'phone' => $this->phone,
            'city' => $this->city,
            'country' => $this->country,
            'address' => $this->address,
            'status' => $this->enumValue($this->status),
            'verification_notes' => $this->verification_notes,
            'commission_rate' => $this->commission_rate !== null ? (float) $this->commission_rate : null,
            'effective_commission_rate' => $this->resource->commissionRate(),
            'uses_default_commission' => $this->commission_rate === null,
            'payment_contract' => PaymentContractResource::make($this->whenLoaded('paymentContract')),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'identity_documents' => $this->when(
                $this->resource instanceof Organization && $this->isCustomer() && $this->relationLoaded('users'),
                fn () => DocumentResource::collection($this->identityDocuments()),
            ),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Document>
     */
    private function identityDocuments()
    {
        return $this->users
            ->filter(fn ($user) => $user instanceof User && $user->relationLoaded('documents'))
            ->flatMap(fn (User $user) => $user->documents)
            ->filter(fn (Document $document) => $document->type === DocumentType::Identity)
            ->values();
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
