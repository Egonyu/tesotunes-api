<?php

use App\Jobs\DispatchArtistUpgradedWebhook;
use App\Jobs\DispatchUserRegisteredWebhook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.n8n.user_registered_webhook' => 'https://n8n.test/webhook/user-registered',
        'services.n8n.artist_upgraded_webhook' => 'https://n8n.test/webhook/artist-upgraded',
    ]);
});

it('posts the new user to the n8n user-registered webhook', function () {
    Http::fake(['n8n.test/*' => Http::response(['message' => 'Workflow was started'])]);
    $user = User::factory()->create();

    (new DispatchUserRegisteredWebhook($user->id))->handle();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://n8n.test/webhook/user-registered'
        && $request['user']['email'] === $user->email);
});

// A rejected webhook must fail the job so the queue retries it, instead of logging "dispatched".
it('fails the user-registered job when n8n rejects the webhook', function () {
    Http::fake(['n8n.test/*' => Http::response(['message' => 'not registered'], 404)]);
    $user = User::factory()->create();

    (new DispatchUserRegisteredWebhook($user->id))->handle();
})->throws(RequestException::class);

it('fails the artist-upgraded job when n8n is down', function () {
    Http::fake(['n8n.test/*' => Http::response('Bad Gateway', 502)]);
    $user = User::factory()->create();

    (new DispatchArtistUpgradedWebhook($user->id))->handle();
})->throws(RequestException::class);

it('skips the webhook when no n8n URL is configured', function () {
    config(['services.n8n.user_registered_webhook' => null]);
    Http::fake();
    $user = User::factory()->create();

    (new DispatchUserRegisteredWebhook($user->id))->handle();

    Http::assertNothingSent();
});
