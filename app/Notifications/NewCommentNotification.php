<?php

namespace App\Notifications;

use App\Channels\ExpoPushChannel;
use App\Models\Comment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewCommentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Comment $comment,
        protected string $commenterName,
        protected string $contentType,
        protected bool $isReply = false
    ) {}

    /**
     * Only push channel — DB notification already created by CommentController
     */
    public function via(object $notifiable): array
    {
        return [ExpoPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        $type = $this->isReply ? 'comment_reply' : 'new_comment';

        return [
            'type' => $type,
            'module' => 'social',
            'comment_id' => $this->comment->id,
            'commenter_id' => $this->comment->user_id,
            'commenter_name' => $this->commenterName,
            'content_preview' => substr($this->comment->content, 0, 100),
            'commentable_type' => $this->comment->commentable_type,
            'commentable_id' => $this->comment->commentable_id,
            'parent_id' => $this->comment->parent_id,
            'title' => $this->isReply ? 'New Reply' : 'New Comment',
            'message' => $this->isReply
                ? "{$this->commenterName} replied to your comment"
                : "{$this->commenterName} commented on your {$this->contentType}",
            'icon' => 'chat-bubble',
            'color' => 'blue',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        $preview = substr($this->comment->content, 0, 60);

        return [
            'title' => $this->isReply ? 'New Reply' : 'New Comment',
            'body' => $this->isReply
                ? "{$this->commenterName} replied: \"{$preview}\""
                : "{$this->commenterName}: \"{$preview}\"",
            'data' => [
                'type' => $this->isReply ? 'comment_reply' : 'comment',
                'commentId' => $this->comment->id,
                'userId' => $this->comment->user_id,
                'userName' => $this->commenterName,
                'comment' => substr($this->comment->content, 0, 100),
                'screen' => 'Comments',
                'params' => [
                    'commentableType' => class_basename($this->comment->commentable_type),
                    'commentableId' => $this->comment->commentable_id,
                ],
            ],
        ];
    }
}
