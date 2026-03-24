<?php

declare(strict_types=1);

use App\Http\Resources\EventResource;
use App\Http\Resources\ForumThreadResource;
use App\Models\Event;
use App\Models\Modules\Forum\ForumTopic;

it('uses named-route compatible links in event resource', function (): void {
    $event = Event::factory()->create();

    $payload = EventResource::make($event)->resolve();

    expect($payload['links']['self'])->toBe(route('api.events.show', ['id' => $event->id]));
    expect($payload['links']['artist'])->toBe(route('api.artist.events.show', ['id' => $event->id]));
    expect($payload['links']['admin'])->toBe(route('api.admin.events.show', ['id' => $event->id]));
    expect($payload['links']['registrations'])->toBe(route('api.admin.events.registrations', ['id' => $event->id]));
});

it('uses named-route compatible links in forum thread resource', function (): void {
    $topic = new ForumTopic;
    $topic->id = 12345;

    $payload = ForumThreadResource::make($topic)->resolve();

    expect($payload['links']['self'])->toBe(route('api.admin.forums.show', ['id' => $topic->id]));
    expect($payload['links']['replies'])->toBe(route('api.admin.forums.replies', ['id' => $topic->id]));
});
