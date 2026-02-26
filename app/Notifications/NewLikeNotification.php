<?php

namespace App\Notifications;

use App\Channels\ExpoPushChannel;
use App\Models\Like;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewLikeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Like $like,
        protected string $likerName,
        protected string $contentTitle,
        protected string $contentType
    ) {}

    /**
     * Only push channel — DB notification already created by Like::toggle()
     */
    public function via(object $notifiable): array
    {
        return [ExpoPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'content_liked',
            'module' => 'social',
            'liker_id' => $this->like->user_id,
            'liker_name' => $this->likerName,
            'likeable_type' => $this->like->likeable_type,
            'likeable_id' => $this->like->likeable_id,
            'content_title' => $this->contentTitle,
            'content_type' => $this->contentType,
            'title' => 'New Like',
            'message' => "{$this->likerName} liked your {$this->contentType}",
            'icon' => 'heart',
            'color' => 'red',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => 'New Like',
            'body' => "{$this->likerName} liked your {$this->contentType} \"{$this->contentTitle}\"",
            'data' => [
                'type' => 'like',
                'likeableType' => class_basename($this->like->likeable_type),
                'likeableId' => $this->like->likeable_id,
                'userId' => $this->like->user_id,
                'userName' => $this->likerName,
                'screen' => 'ContentDetail',
                'params' => [
                    'type' => class_basename($this->like->likeable_type),
                    'id' => $this->like->likeable_id,
                ],
            ],
        ];
    }
}
