<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Push\DomainPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DevicePushTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_token_registration_is_scoped_to_the_signed_in_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->postJson('/api/v1/device-tokens', [
            'token' => 'phone-token',
            'platform' => 'android',
            'app' => 'customer',
            'locale' => 'en',
        ])->assertUnauthorized();

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/device-tokens', [
            'token' => 'phone-token',
            'platform' => 'android',
            'app' => 'customer',
            'locale' => 'en',
        ])->assertOk()->assertJsonPath('data.id', 1);

        $this->assertDatabaseHas('device_tokens', [
            'token' => 'phone-token',
            'user_id' => $owner->id,
            'app' => 'customer',
            'locale' => 'en',
        ]);

        $this->actingAs($other, 'sanctum')->postJson('/api/v1/device-tokens', [
            'token' => 'phone-token',
            'platform' => 'android',
            'app' => 'driver',
            'locale' => 'ar',
        ])->assertOk();

        $this->assertDatabaseHas('device_tokens', [
            'token' => 'phone-token',
            'user_id' => $other->id,
            'app' => 'driver',
            'locale' => 'ar',
        ]);
        $this->assertSame(1, DeviceToken::query()->count());

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/v1/device-tokens', [
            'token' => 'phone-token',
        ])->assertOk();

        $this->assertDatabaseHas('device_tokens', [
            'token' => 'phone-token',
            'user_id' => $other->id,
        ]);

        $this->actingAs($other, 'sanctum')->deleteJson('/api/v1/device-tokens', [
            'token' => 'phone-token',
        ])->assertOk();

        $this->assertDatabaseCount('device_tokens', 0);
    }

    public function test_push_uses_the_device_locale_and_drops_unregistered_tokens(): void
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $config = $directory.DIRECTORY_SEPARATOR.'openssl.cnf';
        file_put_contents($config, "[req]\nprompt = no\ndistinguished_name = dn\n[dn]\nCN = test\n");

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $config,
        ]);
        $this->assertNotFalse($key, 'OpenSSL could not create a test key: '.openssl_error_string());
        $exported = openssl_pkey_export($key, $private, null, ['config' => $config]);
        $this->assertTrue($exported, 'OpenSSL could not export a test key: '.openssl_error_string());

        $path = storage_path('framework/testing/firebase-test-key.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode([
            'client_email' => 'push@movex-test.iam.gserviceaccount.com',
            'private_key' => $private,
        ]));

        config([
            'services.firebase.project_id' => 'movex-test',
            'services.firebase.credentials' => $path,
        ]);

        $user = User::factory()->create();
        $live = DeviceToken::query()->create([
            'user_id' => $user->id,
            'token' => 'live-token',
            'platform' => 'android',
            'app' => 'customer',
            'locale' => 'en',
        ]);
        $stale = DeviceToken::query()->create([
            'user_id' => $user->id,
            'token' => 'stale-token',
            'platform' => 'android',
            'app' => 'customer',
            'locale' => 'ar',
        ]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response([
                    'access_token' => 'test-token',
                    'expires_in' => 3600,
                ]);
            }

            $token = $request->data()['message']['token'] ?? '';
            if ($token === 'stale-token') {
                return Http::response([
                    'error' => [
                        'status' => 'NOT_FOUND',
                        'details' => [
                            ['errorCode' => 'UNREGISTERED'],
                        ],
                    ],
                ], 404);
            }

            return Http::response(['name' => 'projects/movex-test/messages/1']);
        });

        app(DomainPushSender::class)->deliver([$user->id], [
            'event' => 'trip.assigned',
            'title_ar' => 'تم تعيين الرحلة',
            'title_en' => 'Trip assigned',
            'body_ar' => 'السائق في الطريق',
            'body_en' => 'The driver is on the way',
        ], 'trip', 15);

        $this->assertModelMissing($stale);
        $this->assertModelExists($live);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'https://fcm.googleapis.com/v1/projects/movex-test/messages:send')) {
                return false;
            }

            $message = $request->data()['message'] ?? [];
            if (($message['token'] ?? null) !== 'live-token') {
                return false;
            }

            return ($message['notification']['title'] ?? null) === 'Trip assigned'
                && ($message['notification']['body'] ?? null) === 'The driver is on the way'
                && ($message['data']['event'] ?? null) === 'trip.assigned'
                && ($message['data']['target_type'] ?? null) === 'trip'
                && ($message['data']['target_id'] ?? null) === '15'
                && ($message['android']['notification']['channel_id'] ?? null) === 'movex_alerts'
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_push_is_skipped_when_credentials_are_missing(): void
    {
        config(['services.firebase.credentials' => 'missing']);

        $user = User::factory()->create();
        DeviceToken::query()->create([
            'user_id' => $user->id,
            'token' => 'live-token',
            'platform' => 'android',
            'app' => 'provider',
            'locale' => 'ar',
        ]);

        Http::preventStrayRequests();
        Http::fake();

        app(DomainPushSender::class)->deliver([$user->id], [
            'event' => 'job.awarded',
            'title_ar' => 'ترسية',
            'title_en' => 'Awarded',
            'body_ar' => 'عيّن سائقاً',
            'body_en' => 'Assign a driver',
        ], 'job', 4);

        Http::assertNothingSent();
        $this->assertDatabaseCount('device_tokens', 1);
    }
}
