<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_customer_can_register_and_login(): void
    {
        $register = $this->postJson('/api/v1/auth/register/customer', [
            'name' => 'Sara Al Lawati',
            'email' => 'sara@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'account_type' => 'company',
            'company_name' => 'Sara Trading',
            'phone' => '+968 99001122',
        ]);

        $register->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.organization.account_type', 'company')
            ->assertJsonPath('data.user.organization.status', 'pending');
        $this->assertNotEmpty($register->json('data.token'));

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'sara@example.com',
            'password' => 'Password123!',
        ]);

        $login->assertOk()->assertJsonPath('data.user.user_type', 'customer');
        $this->assertDatabaseHas('organizations', [
            'email' => 'sara@example.com',
            'account_type' => 'company',
            'name' => 'Sara Trading',
            'status' => 'pending',
        ]);
    }

    public function test_individual_customer_registers_with_id_card_and_stays_pending(): void
    {
        Storage::fake('local');

        $register = $this->post('/api/v1/auth/register/customer', [
            'name' => 'Sara Al Lawati',
            'email' => 'sara@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'account_type' => 'individual',
            'id_card' => UploadedFile::fake()->create('id-card.pdf', 120, 'application/pdf'),
        ]);

        $register->assertCreated()
            ->assertJsonPath('data.user.organization.account_type', 'individual')
            ->assertJsonPath('data.user.organization.name', 'Sara Al Lawati')
            ->assertJsonPath('data.user.organization.status', 'pending');

        $userId = $register->json('data.user.id');
        $this->assertDatabaseHas('documents', [
            'documentable_type' => User::class,
            'documentable_id' => $userId,
            'type' => 'identity',
        ]);

        $user = User::query()->findOrFail($userId);
        $documentId = $user->documents()->value('id');
        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/documents/'.$documentId.'/file')
            ->assertOk();

        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/organizations/'.$user->organization_id)
            ->assertOk()
            ->assertJsonPath('data.identity_documents.0.type', 'identity');
    }

    public function test_individual_registration_requires_an_id_card(): void
    {
        $this->postJson('/api/v1/auth/register/customer', [
            'name' => 'Sara Al Lawati',
            'email' => 'sara@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'account_type' => 'individual',
        ])->assertUnprocessable()->assertJsonValidationErrors(['id_card']);
    }

    public function test_platform_admin_can_activate_a_pending_customer(): void
    {
        $this->postJson('/api/v1/auth/register/customer', [
            'name' => 'Sara Al Lawati',
            'email' => 'sara@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'account_type' => 'company',
            'company_name' => 'Sara Trading',
        ])->assertCreated();

        $organizationId = User::query()->where('email', 'sara@example.com')->value('organization_id');
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/organizations/'.$organizationId.'/verify', [
                'status' => 'active',
                'verification_notes' => 'Documents checked.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('organizations', [
            'id' => $organizationId,
            'status' => 'active',
        ]);
    }

    public function test_invalid_login_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }
}
