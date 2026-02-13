<?php

namespace App\DTOs\Feed;

class FeedItem implements \JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $type,
        public readonly string $module,
        public readonly ?string $title,
        public readonly ?string $body,
        public readonly array $actor,
        public readonly ?array $media,
        public readonly array $engagement,
        public readonly array $tags = [],
        public readonly array $actions = [],
        public readonly array $extras = [],
        public readonly bool $isPrestige = false,
        public readonly ?string $publishedAt = null,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $this->type,
            'module' => $this->module,
            'title' => $this->title,
            'body' => $this->body,
            'actor' => $this->actor,
            'media' => $this->media,
            'engagement' => $this->engagement,
            'tags' => $this->tags,
            'actions' => $this->actions,
            'extras' => $this->extras,
            'is_prestige' => $this->isPrestige,
            'published_at' => $this->publishedAt,
        ];
    }
}
