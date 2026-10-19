<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:android,ios'],
            'app' => ['required', 'in:customer,provider,driver'],
            'locale' => ['nullable', 'in:ar,en'],
        ]);

        $device = DeviceToken::query()->updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'],
                'app' => $data['app'],
                'locale' => $data['locale'] ?? $request->user()->locale ?? 'ar',
                'last_seen_at' => now(),
            ],
        );

        return ApiResponse::success(['id' => $device->id], 'Device registered.');
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        DeviceToken::query()
            ->where('token', $data['token'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return ApiResponse::success(null, 'Device removed.');
    }
}
