<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Mail\PasswordResetCodeMail;
use App\Models\PasswordResetCode;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_customer_can_reset_a_password_with_the_emailed_code(): void
    {
        $user = $this->account(UserType::Customer, 'Sara@Example.com', 'en');
        $user->createToken('phone');
        $code = $this->requestCode($user);

        $this->assertNotSame($code, PasswordResetCode::query()->value('code_hash'));
        $this->assertSame(
            PasswordResetCode::hashFor('sara@example.com', $code),
            PasswordResetCode::query()->value('code_hash'),
        );

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'sara@example.com',
            'code' => $code,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertOk()->assertJsonPath('message', 'Password updated.');

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertTrue(Hash::check('NewPassword1!', $user->fresh()->password));

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'NewPassword1!',
        ])->assertOk();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'sara@example.com',
            'code' => $code,
            'password' => 'AnotherPass1!',
            'password_confirmation' => 'AnotherPass1!',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_provider_receives_a_reset_code_in_the_account_language(): void
    {
        $user = $this->account(UserType::Provider, 'ops@provider.test', 'ar');
        $code = $this->requestCode($user);

        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use ($user, $code) {
            return $mail->hasTo($user->email)
                && $mail->code === $code
                && $mail->locale === 'ar';
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'ProviderPass1!',
            'password_confirmation' => 'ProviderPass1!',
        ], ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديث كلمة المرور.');
    }

    public function test_unknown_driver_and_admin_addresses_do_not_send_mail(): void
    {
        Mail::fake();
        $this->account(UserType::Driver, 'driver@drivers.mz.local');
        $this->account(UserType::Platform, 'admin@movex.test');

        foreach (['missing@example.com', 'driver@drivers.mz.local', 'admin@movex.test'] as $email) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])
                ->assertOk()
                ->assertJsonPath('message', 'If an account exists for this email, a reset code is on its way.');
        }

        Mail::assertNothingSent();
    }

    public function test_arabic_request_uses_the_same_generic_response(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'missing@example.com',
        ], ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('message', 'إذا كان هناك حساب لهذا البريد، فسيصلك رمز إعادة التعيين.');
    }

    public function test_wrong_codes_lock_after_the_attempt_limit(): void
    {
        config(['mz.password_reset.max_attempts' => 3]);
        $user = $this->account(UserType::Customer, 'sara@example.com');
        $this->requestCode($user);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->postJson('/api/v1/auth/reset-password', $this->resetPayload('000000'))
                ->assertUnprocessable()
                ->assertJsonPath('errors.code.0', 'This code is invalid or has expired.');
        }

        $this->postJson('/api/v1/auth/reset-password', $this->resetPayload('000000'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Too many attempts. Request a new code.');

        $this->assertTrue(Hash::check('Password123!', $user->fresh()->password));
    }

    public function test_expired_code_is_rejected(): void
    {
        $user = $this->account(UserType::Customer, 'sara@example.com');
        $code = $this->requestCode($user);

        $this->travel(16)->minutes();

        try {
            $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($code))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('code');
        } finally {
            $this->travelBack();
        }
    }

    public function test_a_second_request_inside_the_cooldown_does_not_send_another_code(): void
    {
        $user = $this->account(UserType::Customer, 'sara@example.com', 'en');
        Mail::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        Mail::assertSent(PasswordResetCodeMail::class, 1);
    }

    public function test_reset_mail_renders_the_code_in_both_languages(): void
    {
        $arabic = new PasswordResetCodeMail('482915', 'Sara', 15, 'ar');
        $arabic->assertSeeInHtml('482915');
        $arabic->assertSeeInHtml('مرحباً Sara');
        $arabic->assertSeeInText('482915');
        $this->assertStringContainsString('dir="rtl"', (string) $arabic->render());

        $english = new PasswordResetCodeMail('482915', 'Sara', 15, 'en');
        $english->assertSeeInHtml('Hello Sara');
        $this->assertStringContainsString('dir="ltr"', (string) $english->render());
        $this->assertSame('Your MoveX password reset code', $english->envelope()->subject);
    }

    private function account(UserType $type, string $email, string $locale = 'ar'): User
    {
        return User::factory()->create([
            'name' => 'Sara Al Lawati',
            'email' => $email,
            'locale' => $locale,
            'user_type' => $type,
            'password' => 'Password123!',
            'is_active' => true,
            'must_set_password' => false,
        ]);
    }

    private function requestCode(User $user): string
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $user->email,
        ])->assertOk();

        $code = null;
        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use (&$code, $user) {
            $code = $mail->code;

            return $mail->hasTo($user->email);
        });

        $this->assertIsString($code);
        $this->assertSame(6, strlen($code));

        return $code;
    }

    /**
     * @return array<string, string>
     */
    private function resetPayload(string $code): array
    {
        return [
            'email' => 'sara@example.com',
            'code' => $code,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ];
    }
}
