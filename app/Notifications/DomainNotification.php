<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class DomainNotification extends Notification
{
    /**
     * @param  array{event: string, title_ar: string, title_en: string, body_ar: string, body_en: string}  $copy
     */
    public function __construct(
        private readonly array $copy,
        private readonly string $targetType,
        private readonly int|string|null $targetId,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->copy['event'],
            'title_ar' => $this->copy['title_ar'],
            'title_en' => $this->copy['title_en'],
            'body_ar' => $this->copy['body_ar'],
            'body_en' => $this->copy['body_en'],
            'title' => $this->copy['title_ar'],
            'body' => $this->copy['body_ar'],
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
        ];
    }
}
