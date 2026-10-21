<?php

namespace App\Services;

use App\Enums\UserType;
use App\Mail\PasswordResetCodeMail;
use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasswordResetService
{
    public function requestCode(string $email): void
    {
        $email = $this->normalizeEmail($email);
        $user = $this->eligibleUser($email);

        if (! $user) {
            return;
        }

        $cooldownKey = 'password-reset-sent:'.hash('sha256', $email);
        if (Cache::has($cooldownKey)) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresMinutes = $this->expiresMinutes();

        DB::transaction(function () use ($email, $code, $expiresMinutes): void {
            PasswordResetCode::query()
                ->where('email', $email)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            PasswordResetCode::query()->create([
                'email' => $email,
                'code_hash' => PasswordResetCode::hashFor($email, $code),
                'expires_at' => now()->addMinutes($expiresMinutes),
            ]);
        });

        try {
            Mail::to($user->email)->send(new PasswordResetCodeMail(
                code: $code,
                recipientName: $user->name,
                expiresMinutes: $expiresMinutes,
                locale: $this->mailLocale($user),
            ));
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        Cache::put($cooldownKey, true, now()->addMinute());
    }

    public function reset(string $email, string $codeInput, string $password): void
    {
        $email = $this->normalizeEmail($email);
        $code = preg_replace('/\D+/', '', $codeInput) ?? '';

        if (strlen($code) !== 6) {
            throw ValidationException::withMessages([
                'code' => [__('mail.password_reset.invalid')],
            ]);
        }

        $maxAttempts = max(1, (int) config('mz.password_reset.max_attempts', 5));

        $outcome = DB::transaction(function () use ($email, $code, $password, $maxAttempts): string {
            $record = PasswordResetCode::query()
                ->where('email', $email)
                ->whereNull('used_at')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $record || ! $record->isUsable()) {
                return 'invalid';
            }

            if ($record->attempts >= $maxAttempts) {
                $record->forceFill(['used_at' => now()])->save();

                return 'locked';
            }

            if (! hash_equals($record->code_hash, PasswordResetCode::hashFor($email, $code))) {
                $record->increment('attempts');

                if ($record->attempts >= $maxAttempts) {
                    $record->forceFill(['used_at' => now()])->save();

                    return 'locked';
                }

                return 'invalid';
            }

            $record->forceFill(['used_at' => now()])->save();

            $user = $this->eligibleUser($email);
            if (! $user) {
                return 'invalid';
            }

            $user->forceFill([
                'password' => $password,
                'must_set_password' => false,
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();

            PasswordResetCode::query()
                ->where('email', $email)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            event(new PasswordReset($user));

            return 'reset';
        });

        if ($outcome === 'locked') {
            throw ValidationException::withMessages([
                'code' => [__('mail.password_reset.locked')],
            ]);
        }

        if ($outcome !== 'reset') {
            throw ValidationException::withMessages([
                'code' => [__('mail.password_reset.invalid')],
            ]);
        }
    }

    private function eligibleUser(string $email): ?User
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user || $user->usesTechnicalEmail()) {
            return null;
        }

        if (! in_array($user->user_type, [UserType::Customer, UserType::Provider], true)) {
            return null;
        }

        return $user;
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function expiresMinutes(): int
    {
        return max(1, (int) config('mz.password_reset.expires_minutes', 15));
    }

    private function mailLocale(User $user): string
    {
        return in_array($user->locale, ['ar', 'en'], true) ? (string) $user->locale : 'ar';
    }
}
