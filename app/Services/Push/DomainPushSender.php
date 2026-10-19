<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DomainPushSender
{
    public function __construct(private readonly FcmClient $fcm) {}

    /**
     * Send after the current transaction commits and the HTTP response is out.
     *
     * @param  list<int>  $userIds
     * @param  array{event: string, title_ar: string, title_en: string, body_ar: string, body_en: string}  $copy
     */
    public function schedule(array $userIds, array $copy, string $targetType, int|string|null $targetId): void
    {
        $userIds = array_values(array_unique(array_map(intval(...), $userIds)));
        if ($userIds === []) {
            return;
        }

        $deliver = function () use ($userIds, $copy, $targetType, $targetId): void {
            app()->terminating(function () use ($userIds, $copy, $targetType, $targetId): void {
                $this->deliver($userIds, $copy, $targetType, $targetId);
            });
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($deliver);
        } else {
            $deliver();
        }
    }

    /**
     * @param  list<int>  $userIds
     * @param  array{event: string, title_ar: string, title_en: string, body_ar: string, body_en: string}  $copy
     */
    public function deliver(array $userIds, array $copy, string $targetType, int|string|null $targetId): void
    {
        if ($userIds === []) {
            return;
        }

        try {
            $messages = [];
            DeviceToken::query()
                ->whereIn('user_id', $userIds)
                ->orderBy('id')
                ->each(function (DeviceToken $device) use (&$messages, $copy, $targetType, $targetId): void {
                    $english = $device->locale === 'en';
                    $messages[] = [
                        'id' => $device->id,
                        'token' => $device->token,
                        'title' => $english ? $copy['title_en'] : $copy['title_ar'],
                        'body' => $english ? $copy['body_en'] : $copy['body_ar'],
                        'data' => [
                            'event' => (string) $copy['event'],
                            'target_type' => $targetType,
                            'target_id' => (string) ($targetId ?? ''),
                        ],
                    ];
                });

            if ($messages === []) {
                return;
            }

            $results = $this->fcm->sendBatch($messages);
            $staleIds = [];
            foreach ($results as $id => $result) {
                if ($result === FcmDelivery::Stale) {
                    $staleIds[] = $id;
                }
            }

            if ($staleIds !== []) {
                DeviceToken::query()->whereKey($staleIds)->delete();
            }
        } catch (Throwable $exception) {
            Log::warning('Push delivery failed.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
