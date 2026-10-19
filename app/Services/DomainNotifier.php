<?php

namespace App\Services;

use App\Enums\OfferSelectionMode;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\QuotationStatus;
use App\Enums\SettlementSource;
use App\Enums\UserType;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PlatformOffer;
use App\Models\Quotation;
use App\Models\Settlement;
use App\Models\ShipmentRequest;
use App\Models\TransportJob;
use App\Models\Trip;
use App\Models\User;
use App\Notifications\DomainNotification;
use App\Services\Push\DomainPushSender;
use App\Support\NotificationCopy;
use App\Support\Permissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class DomainNotifier
{
    public function __construct(private readonly DomainPushSender $push) {}

    public function shipmentPublished(ShipmentRequest $shipment, User $actor): void
    {
        $copy = $this->shipmentCopy('shipment.published', $shipment);
        $adminCopy = $this->shipmentCopy('shipment.published_admin', $shipment);

        $this->eachActiveProvider($actor, function (Collection $users) use ($copy, $shipment) {
            $this->send($users, $copy, 'shipment', $shipment->id);
        });
        $this->notifyPlatform(Permissions::SHIPMENTS_VIEW, $adminCopy, 'shipment', $shipment->id, $actor);
    }

    public function shipmentCancelled(ShipmentRequest $shipment, User $actor): void
    {
        $copy = NotificationCopy::make('shipment.cancelled', ['reference' => $shipment->reference]);
        $organizationIds = $shipment->quotations()
            ->where('status', '!=', QuotationStatus::Withdrawn->value)
            ->pluck('provider_organization_id')
            ->unique();

        foreach ($organizationIds as $organizationId) {
            $this->notifyProviderOrganization(
                (int) $organizationId,
                $copy,
                'shipment',
                $shipment->id,
                $actor,
                Permissions::QUOTATIONS_VIEW,
            );
        }
    }

    public function quotationSubmitted(Quotation $quotation, User $actor, bool $onBehalf = false): void
    {
        $quotation->loadMissing('shipmentRequest');
        $shipment = $quotation->shipmentRequest;
        $replace = ['reference' => $shipment->reference];

        if ($shipment->offer_selection_mode === OfferSelectionMode::Customer) {
            $this->notifyCompany(
                (int) $shipment->customer_organization_id,
                UserType::Customer,
                NotificationCopy::make('quotation.received', $replace),
                'quotation',
                $quotation->id,
                $actor,
            );
        }

        $this->notifyPlatform(
            Permissions::QUOTATIONS_VIEW,
            NotificationCopy::make('quotation.received_admin', $replace),
            'quotation',
            $quotation->id,
            $actor,
        );

        if ($onBehalf) {
            $this->notifyProviderOrganization(
                (int) $quotation->provider_organization_id,
                NotificationCopy::make('quotation.on_behalf', $replace),
                'quotation',
                $quotation->id,
                $actor,
                Permissions::QUOTATIONS_VIEW,
            );
        }
    }

    public function quotationWithdrawn(Quotation $quotation, User $actor): void
    {
        $quotation->loadMissing('shipmentRequest');
        $shipment = $quotation->shipmentRequest;
        if ($shipment->offer_selection_mode !== OfferSelectionMode::Customer) {
            return;
        }

        $this->notifyCompany(
            (int) $shipment->customer_organization_id,
            UserType::Customer,
            NotificationCopy::make('quotation.withdrawn', ['reference' => $shipment->reference]),
            'quotation',
            $quotation->id,
            $actor,
        );
    }

    public function platformOfferPublished(PlatformOffer $offer, User $actor): void
    {
        $offer->loadMissing(['shipmentRequest', 'quotation.providerOrganization']);
        $shipment = $offer->shipmentRequest;
        $replace = ['reference' => $shipment->reference];

        $this->notifyCompany(
            (int) $shipment->customer_organization_id,
            UserType::Customer,
            NotificationCopy::make('platform_offer.published', $replace),
            'shipment',
            $shipment->id,
            $actor,
        );

        $provider = $offer->quotation?->providerOrganization;
        if ($provider?->isProvider()) {
            $this->notifyCompany(
                $provider->id,
                UserType::Provider,
                NotificationCopy::make('platform_offer.based_on_quote', $replace),
                'quotation',
                $offer->quotation_id,
                $actor,
            );
        }
    }

    public function platformOfferWithdrawn(PlatformOffer $offer, ?User $actor = null): void
    {
        $offer->loadMissing(['shipmentRequest', 'quotation.providerOrganization']);
        $shipment = $offer->shipmentRequest;
        if (! $shipment) {
            return;
        }

        $replace = ['reference' => $shipment->reference];
        $this->notifyCompany(
            (int) $shipment->customer_organization_id,
            UserType::Customer,
            NotificationCopy::make('platform_offer.withdrawn', $replace),
            'shipment',
            $shipment->id,
            $actor,
        );

        $provider = $offer->quotation?->providerOrganization;
        if ($provider?->isProvider()) {
            $this->notifyCompany(
                $provider->id,
                UserType::Provider,
                NotificationCopy::make('platform_offer.basis_withdrawn', $replace),
                'quotation',
                $offer->quotation_id,
                $actor,
            );
        }
    }

    /**
     * @param  Collection<int, int|string>  $losingOrganizationIds
     */
    public function jobAwarded(TransportJob $job, ShipmentRequest $shipment, ?User $actor, Collection $losingOrganizationIds): void
    {
        $replace = [
            'reference' => $shipment->reference,
            'job' => $job->reference,
        ];

        $this->notifyCompany(
            (int) $job->customer_organization_id,
            UserType::Customer,
            NotificationCopy::make('job.created', $replace),
            'job',
            $job->id,
            $actor,
        );
        $this->notifyProviderOrganization(
            (int) $job->provider_organization_id,
            NotificationCopy::make('job.awarded', $replace),
            'job',
            $job->id,
            $actor,
            Permissions::TRIPS_ASSIGN,
        );

        $rejected = NotificationCopy::make('quotation.rejected', ['reference' => $shipment->reference]);
        foreach ($losingOrganizationIds->unique() as $organizationId) {
            $this->notifyProviderOrganization(
                (int) $organizationId,
                $rejected,
                'shipment',
                $shipment->id,
                $actor,
                Permissions::QUOTATIONS_VIEW,
            );
        }
    }

    public function paymentFailed(Payment $payment): void
    {
        $payment->loadMissing('shipmentRequest');
        if (! $payment->payer_organization_id) {
            return;
        }

        $this->notifyCompany(
            (int) $payment->payer_organization_id,
            UserType::Customer,
            NotificationCopy::make('payment.failed', [
                'reference' => $payment->shipmentRequest?->reference ?? $payment->reference,
            ]),
            'payment',
            $payment->id,
        );
    }

    public function receiptUploaded(Payment $payment, User $actor): void
    {
        $this->notifyPlatform(
            Permissions::PAYMENTS_MANAGE,
            NotificationCopy::make('payment.receipt_uploaded', ['reference' => $payment->reference]),
            'payment',
            $payment->id,
            $actor,
        );
    }

    public function tripAssigned(Trip $trip, User $driver, ?User $previousDriver, User $actor): void
    {
        if ($previousDriver && $previousDriver->id === $driver->id) {
            return;
        }

        $trip->loadMissing('transportJob');
        $job = $trip->transportJob;
        $replace = [
            'reference' => $trip->reference,
            'driver' => $driver->name,
            'pickup' => $trip->pickup_city,
            'delivery' => $trip->delivery_city,
        ];

        $this->send(
            collect([$driver])->filter(fn (User $user) => $user->is_active && $user->id !== $actor->id),
            NotificationCopy::make('trip.assigned_driver', $replace),
            'trip',
            $trip->id,
        );

        if ($previousDriver) {
            $this->send(
                collect([$previousDriver])->filter(fn (User $user) => $user->is_active && $user->id !== $actor->id),
                NotificationCopy::make('trip.reassigned', $replace),
                'trip',
                $trip->id,
            );
        }

        if ($job) {
            $this->notifyCompany(
                (int) $job->customer_organization_id,
                UserType::Customer,
                NotificationCopy::make('trip.assigned', $replace),
                'trip',
                $trip->id,
                $actor,
            );
        }
    }

    public function tripInTransit(Trip $trip, User $actor): void
    {
        $trip->loadMissing('transportJob');
        $job = $trip->transportJob;
        if (! $job) {
            return;
        }

        $this->notifyCompany(
            (int) $job->customer_organization_id,
            UserType::Customer,
            NotificationCopy::make('trip.in_transit', ['reference' => $trip->reference]),
            'trip',
            $trip->id,
            $actor,
        );
    }

    public function tripCompleted(Trip $trip, User $actor): void
    {
        $trip->loadMissing('transportJob');
        $job = $trip->transportJob;
        if (! $job) {
            return;
        }

        $replace = ['reference' => $trip->reference];
        $this->notifyCompany(
            (int) $job->customer_organization_id,
            UserType::Customer,
            NotificationCopy::make('trip.completed', $replace),
            'trip',
            $trip->id,
            $actor,
        );
        $this->notifyProviderOrganization(
            (int) $job->provider_organization_id,
            NotificationCopy::make('trip.completed_provider', $replace),
            'trip',
            $trip->id,
            $actor,
            Permissions::TRIPS_VIEW,
        );
    }

    public function tripCancelled(Trip $trip, User $actor): void
    {
        $trip->loadMissing(['transportJob', 'driver']);
        $job = $trip->transportJob;
        $replace = ['reference' => $trip->reference];
        $copy = NotificationCopy::make('trip.cancelled', $replace);

        if ($job) {
            $this->notifyCompany(
                (int) $job->customer_organization_id,
                UserType::Customer,
                $copy,
                'trip',
                $trip->id,
                $actor,
            );
            $this->notifyProviderOrganization(
                (int) $job->provider_organization_id,
                $copy,
                'trip',
                $trip->id,
                $actor,
                Permissions::TRIPS_VIEW,
            );
        }

        if ($trip->driver) {
            $this->send(
                collect([$trip->driver])->filter(fn (User $user) => $user->is_active && $user->id !== $actor->id),
                NotificationCopy::make('trip.cancelled_driver', $replace),
                'trip',
                $trip->id,
            );
        }
    }

    public function jobCompleted(TransportJob $job): void
    {
        $replace = ['job' => $job->reference];
        $this->notifyCompany(
            (int) $job->customer_organization_id,
            UserType::Customer,
            NotificationCopy::make('job.completed', $replace),
            'job',
            $job->id,
        );
        $this->notifyProviderOrganization(
            (int) $job->provider_organization_id,
            NotificationCopy::make('job.completed_provider', $replace),
            'job',
            $job->id,
            null,
            Permissions::WALLETS_VIEW,
        );
    }

    public function invoiceDue(Invoice $invoice): void
    {
        $this->notifyCompany(
            (int) $invoice->organization_id,
            UserType::Customer,
            NotificationCopy::make('invoice.due', ['reference' => $invoice->reference]),
            'invoice',
            $invoice->id,
        );
    }

    public function providerRegistered(Organization $organization): void
    {
        $this->notifyPlatform(
            Permissions::PROVIDERS_VERIFY,
            NotificationCopy::make('provider.registered', ['name' => $organization->name]),
            'organization',
            $organization->id,
        );
    }

    public function organizationStatusChanged(Organization $organization, User $actor): void
    {
        $event = match ($organization->status) {
            OrganizationStatus::Active => 'organization.activated',
            OrganizationStatus::Rejected => 'organization.rejected',
            OrganizationStatus::Suspended => 'organization.suspended',
            default => null,
        };

        if ($event === null || ! $organization->isProvider()) {
            return;
        }

        $this->notifyCompany(
            $organization->id,
            UserType::Provider,
            NotificationCopy::make($event, ['name' => $organization->name]),
            'organization',
            $organization->id,
            $actor,
        );
    }

    public function settlementRequested(Settlement $settlement, User $actor): void
    {
        if ($settlement->source !== SettlementSource::Provider) {
            return;
        }

        $settlement->loadMissing('providerOrganization');
        $this->notifyPlatform(
            Permissions::SETTLEMENTS_MANAGE,
            NotificationCopy::make('settlement.requested', [
                'name' => $settlement->providerOrganization?->name,
                'amount' => number_format((float) $settlement->amount, 3, '.', ''),
            ]),
            'settlement',
            $settlement->id,
            $actor,
        );
    }

    public function settlementCompleted(Settlement $settlement, User $actor): void
    {
        $this->notifyProviderOrganization(
            (int) $settlement->provider_organization_id,
            NotificationCopy::make('settlement.completed', ['reference' => $settlement->reference]),
            'settlement',
            $settlement->id,
            $actor,
            Permissions::SETTLEMENTS_VIEW,
        );
    }

    /**
     * @param  array{event: string, title_ar: string, title_en: string, body_ar: string, body_en: string}  $copy
     */
    private function notifyCompany(
        int $organizationId,
        UserType $type,
        array $copy,
        string $targetType,
        int|string|null $targetId,
        ?User $except = null,
    ): void {
        $query = User::query()
            ->where('organization_id', $organizationId)
            ->where('user_type', $type)
            ->where('is_active', true)
            ->orderBy('id');

        if ($except) {
            $query->whereKeyNot($except->id);
        }

        $query->chunkById(100, function (Collection $users) use ($copy, $targetType, $targetId) {
            $this->send($users, $copy, $targetType, $targetId);
        });
    }

    /**
     * @param  array{event: string, title_ar: string, title_en: string, body_ar: string, body_en: string}  $copy
     */
    private function notifyProviderOrganization(
        int $organizationId,
        array $copy,
        string $targetType,
        int|string|null $targetId,
        ?User $except,
        string $platformPermission,
    ): void {
        $organization = Organization::query()->find($organizationId);
        if (! $organization) {
            return;
        }

        if ($organization->isPlatform()) {
            $this->notifyPlatform($platformPermission, $copy, $targetType, $targetId, $except);

            return;
        }

        if (! $organization->isProvider()) {
            return;
        }

        $this->notifyCompany($organizationId, UserType::Provider, $copy, $targetType, $targetId, $except);
    }

    /**
     * @param  array{event: string, title_ar: string, title_en: string, body_ar: string, body_en: string}  $copy
     */
    private function notifyPlatform(
        string $permission,
        array $copy,
        string $targetType,
        int|string|null $targetId,
        ?User $except = null,
    ): void {
        $query = User::permission($permission)
            ->where('user_type', UserType::Platform)
            ->where('is_active', true);

        if ($except) {
            $query->whereKeyNot($except->id);
        }

        $this->send($query->get(), $copy, $targetType, $targetId);
    }

    /**
     * @param  callable(Collection<int, User>): void  $send
     */
    private function eachActiveProvider(?User $except, callable $send): void
    {
        $query = User::query()
            ->where('user_type', UserType::Provider)
            ->where('is_active', true)
            ->whereHas('organization', function ($organization) {
                $organization->where('type', OrganizationType::Provider)
                    ->where('status', OrganizationStatus::Active);
            })
            ->orderBy('id');

        if ($except) {
            $query->whereKeyNot($except->id);
        }

        $query->chunkById(100, $send);
    }

    /**
     * @param  array{event: string, title_ar: string, title_en: string, body_ar: string, body_en: string}  $copy
     * @param  Collection<int, User>  $users
     */
    private function send(Collection $users, array $copy, string $targetType, int|string|null $targetId): void
    {
        $recipients = $users->filter(fn ($user) => $user instanceof User)->unique('id')->values();
        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new DomainNotification($copy, $targetType, $targetId));
        $this->push->schedule(
            $recipients->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $copy,
            $targetType,
            $targetId,
        );
    }

    /**
     * @return array{event: string, title_ar: string, title_en: string, body_ar: string, body_en: string}
     */
    private function shipmentCopy(string $event, ShipmentRequest $shipment): array
    {
        return NotificationCopy::make($event, [
            'reference' => $shipment->reference,
            'pickup' => $shipment->pickup_city,
            'delivery' => $shipment->delivery_city,
        ]);
    }
}
