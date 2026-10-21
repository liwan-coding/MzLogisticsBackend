<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterCustomerRequest;
use App\Http\Requests\Auth\RegisterProviderRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\PasswordResetService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly PasswordResetService $passwordResets,
    ) {}

    public function registerCustomer(RegisterCustomerRequest $request): JsonResponse
    {
        $result = $this->authService->registerCustomer($request->validated());

        return ApiResponse::success([
            'token' => $result['token'],
            'user' => UserResource::make($result['user']),
        ], 'Account created.', 201);
    }

    public function registerProvider(RegisterProviderRequest $request): JsonResponse
    {
        $result = $this->authService->registerProvider($request->validated());

        return ApiResponse::success([
            'token' => $result['token'],
            'user' => UserResource::make($result['user']),
        ], 'Provider account created and pending review.', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->identifier(), $request->string('password')->toString());

        return ApiResponse::success([
            'token' => $result['token'],
            'user' => UserResource::make($result['user']),
        ], 'Signed in.');
    }

    public function activateDriver(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string', 'max:16'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $result = $this->authService->activateDriver($data['phone'], $data['code'], $data['password']);

        return ApiResponse::success([
            'token' => $result['token'],
            'user' => UserResource::make($result['user']),
        ], 'Account activated.');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return ApiResponse::success(null, 'Signed out.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['organization', 'roles', 'permissions', 'driverProfile']);

        return ApiResponse::success(UserResource::make($user));
    }

    public function updateMe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['sometimes', 'in:ar,en'],
        ]);

        $request->user()->fill($data)->save();

        return ApiResponse::success(UserResource::make($request->user()->fresh(['organization', 'roles', 'permissions'])));
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $request->user()->forceFill(['password' => $data['password']])->save();

        return ApiResponse::success(null, 'Password updated.');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $this->useRequestLocale($request);
        $request->validate(['email' => ['required', 'email']]);
        $this->passwordResets->requestCode($request->string('email')->toString());

        return ApiResponse::success(null, __('mail.password_reset.sent'));
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $this->useRequestLocale($request);
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'max:16'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $this->passwordResets->reset($data['email'], $data['code'], $data['password']);

        return ApiResponse::success(null, __('mail.password_reset.updated'));
    }

    private function useRequestLocale(Request $request): void
    {
        $locale = $request->getPreferredLanguage(['ar', 'en']);
        if (is_string($locale)) {
            app()->setLocale($locale);
        }
    }
}
