<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TruckType;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\Trip;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_admin_mode_notifies_providers_and_staff_without_showing_quotes_to_the_customer(): void
    {
        [$customer, $provider, $admin] = $this->makeActors();

        $shipmentId = $this->publishShipment($customer);

        $this->assertSame([], $this->events($customer));
        $this->assertContains('shipment.published', $this->events($provider));
        $this->assertContains('shipment.published_admin', $this->events($admin));

        $quotation = $this->submitQuotation($provider, $shipmentId, 100);
        $this->assertNotContains('quotation.received', $this->events($customer));
        $this->assertNotContains('quotation.received_admin', $this->events($provider));
        $this->assertContains('quotation.received_admin', $this->events($admin));

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/shipments/{$shipmentId}/platform-offers", [
            'quotation_id' => $quotation['id'],
            'customer_price' => 130,
        ])->assertCreated();

        $this->assertContains('platform_offer.published', $this->events($customer));
        $this->assertNotContains('platform_offer.published', $this->events($admin));
        $this->assertContains('platform_offer.based_on_quote', $this->events($provider));

        $offerId = $customer->notifications()->get()->first(fn ($notification) => $notification->data['event'] === 'platform_offer.published');
        $this->assertSame('shipment', $offerId->data['target_type']);
        $this->assertNotSame('', $offerId->data['title_ar']);
        $this->assertNotSame('', $offerId->data['title_en']);
    }

    public function test_customer_mode_award_assignment_and_cancellation_reach_the_other_party(): void
    {
        [$customer, $provider, $admin] = $this->makeActors();
        $colleague = User::factory()->create([
            'user_type' => UserType::Customer,
            'organization_id' => $customer->organization_id,
        ]);
        $colleague->assignRole('Company Admin');
        $providerColleague = User::factory()->create([
            'user_type' => UserType::Provider,
            'organization_id' => $provider->organization_id,
            'email' => 'provider-ops@example.com',
        ]);
        $providerColleague->assignRole('Provider Admin');
        $loser = $this->makeProvider('Other Haul', 'other@example.com');

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/settings/offer-selection', [
            'offer_selection_mode' => 'customer',
        ])->assertOk();

        $shipmentId = $this->publishShipment($customer);
        $winner = $this->submitQuotation($provider, $shipmentId, 80);
        $this->submitQuotation($loser, $shipmentId, 90);

        $this->assertContains('quotation.received', $this->events($customer));
        $this->assertNotContains('quotation.received', $this->events($provider));

        $accepted = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/quotations/'.$winner['id'].'/accept', [
            'payment_method' => 'cash',
        ])->assertOk();

        $jobId = $accepted->json('data.id');
        $this->assertNotContains('job.created', $this->events($customer));
        $this->assertContains('job.created', $this->events($colleague));
        $this->assertContains('job.awarded', $this->events($provider));
        $this->assertContains('quotation.rejected', $this->events($loser));

        $tripId = Trip::query()->where('transport_job_id', $jobId)->value('id');
        $driverId = $this->actingAs($provider, 'sanctum')->postJson('/api/v1/drivers', [
            'name' => 'Salim',
            'phone' => '99330011',
        ])->assertCreated()->json('data.driver.id');
        $secondDriverId = $this->actingAs($provider, 'sanctum')->postJson('/api/v1/drivers', [
            'name' => 'Hamed',
            'phone' => '99330012',
        ])->assertCreated()->json('data.driver.id');
        $truckId = $this->actingAs($provider, 'sanctum')->postJson('/api/v1/trucks', [
            'plate_number' => 'MX 8801',
            'type' => TruckType::Flatbed->value,
            'capacity_tons' => 30,
        ])->assertCreated()->json('data.id');

        $this->actingAs($provider, 'sanctum')->postJson("/api/v1/trips/{$tripId}/assign", [
            'truck_id' => $truckId,
            'driver_id' => $driverId,
            'departure_time' => '10:00',
        ])->assertOk();

        $driver = User::query()->findOrFail($driverId);
        $this->assertContains('trip.assigned_driver', $this->events($driver));
        $this->assertContains('trip.assigned', $this->events($customer));
        $this->assertNotContains('trip.assigned_driver', $this->events($provider));

        $this->actingAs($provider, 'sanctum')->postJson("/api/v1/trips/{$tripId}/assign", [
            'truck_id' => $truckId,
            'driver_id' => $secondDriverId,
            'departure_time' => '11:00',
        ])->assertOk();
        $this->assertContains('trip.reassigned', $this->events($driver));

        foreach (['arrived_at_pickup', 'loaded', 'in_transit'] as $status) {
            $this->actingAs($provider, 'sanctum')->postJson("/api/v1/trips/{$tripId}/status", [
                'status' => $status,
            ])->assertOk();
        }
        $this->assertContains('trip.in_transit', $this->events($customer));
        $this->assertNotContains('trip.in_transit', $this->events($provider));

        $this->actingAs($provider, 'sanctum')->postJson("/api/v1/trips/{$tripId}/status", [
            'status' => 'cancelled',
        ])->assertOk();

        $secondDriver = User::query()->findOrFail($secondDriverId);
        $this->assertContains('trip.cancelled', $this->events($customer));
        $this->assertContains('trip.cancelled', $this->events($providerColleague));
        $this->assertNotContains('trip.cancelled', $this->events($provider));
        $this->assertContains('trip.cancelled_driver', $this->events($secondDriver));
    }

    public function test_provider_registration_and_activation_notify_the_right_side(): void
    {
        [, , $admin] = $this->makeActors();

        $this->postJson('/api/v1/auth/register/provider', [
            'name' => 'New Haulier',
            'email' => 'new-haulier@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'company_name' => 'New Haul LLC',
        ])->assertCreated();

        $this->assertContains('provider.registered', $this->events($admin));

        $provider = User::query()->where('email', 'new-haulier@example.com')->firstOrFail();
        $this->assertNotContains('provider.registered', $this->events($provider));

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/organizations/'.$provider->organization_id.'/verify', [
            'status' => OrganizationStatus::Active->value,
        ])->assertOk();

        $this->assertContains('organization.activated', $this->events($provider));
        $this->assertNotContains('organization.activated', $this->events($admin));
    }

    /**
     * @return list<string>
     */
    private function events(User $user): array
    {
        return $user->notifications()->get()
            ->map(fn ($notification) => $notification->data['event'])
            ->all();
    }

    /**
     * @return array{0: User, 1: User, 2: User}
     */
    private function makeActors(): array
    {
        $customerOrg = Organization::query()->create([
            'type' => OrganizationType::Customer,
            'account_type' => AccountType::Company,
            'name' => 'Acme Trading',
            'status' => OrganizationStatus::Active,
        ]);
        $customer = User::factory()->create([
            'user_type' => UserType::Customer,
            'organization_id' => $customerOrg->id,
        ]);
        $customer->assignRole('Company Admin');

        $provider = $this->makeProvider();
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        return [$customer, $provider, $admin];
    }

    private function makeProvider(string $name = 'Fast Haul', string $email = 'provider@example.com'): User
    {
        $providerOrg = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => $name,
            'status' => OrganizationStatus::Active,
        ]);
        $provider = User::factory()->create([
            'user_type' => UserType::Provider,
            'organization_id' => $providerOrg->id,
            'email' => $email,
        ]);
        $provider->assignRole('Provider Admin');

        return $provider;
    }

    private function publishShipment(User $customer): int
    {
        return $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            'cargo_type' => 'Cement',
            'truck_type' => 'flatbed',
            'weight_tons' => 20,
            'quantity' => 20,
            'pickup_address' => 'Sohar Port',
            'pickup_city' => 'Sohar',
            'delivery_address' => 'Nizwa',
            'delivery_city' => 'Nizwa',
            'required_date' => now()->addDay()->toDateString(),
            'publish' => true,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function submitQuotation(User $provider, int $shipmentId, float $price): array
    {
        return $this->actingAs($provider, 'sanctum')->postJson("/api/v1/shipments/{$shipmentId}/quotations", [
            'total_price' => $price,
            'truck_count' => 1,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 30,
            'trip_count' => 1,
            'quantity_per_trip' => 20,
            'duration_days' => 2,
            'transport_start_date' => now()->addDay()->toDateString(),
        ])->assertCreated()->json('data');
    }
}
