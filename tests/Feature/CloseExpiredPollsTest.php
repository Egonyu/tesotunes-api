<?php

use App\Models\Modules\Forum\Poll;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('closes active polls whose end date has passed', function () {
    $expired = Poll::factory()->expired()->create();

    $this->artisan('polls:close-expired')->assertSuccessful();

    expect($expired->fresh()->status)->toBe('closed');
});

it('does not close active polls that have not yet expired', function () {
    $future = Poll::factory()->create(['ends_at' => now()->addDay()]);

    $this->artisan('polls:close-expired')->assertSuccessful();

    expect($future->fresh()->status)->toBe('active');
});

it('does not touch already closed polls', function () {
    $closed = Poll::factory()->closed()->create(['ends_at' => now()->subHour()]);

    $this->artisan('polls:close-expired')->assertSuccessful();

    expect($closed->fresh()->status)->toBe('closed');
});

it('reports the number of polls closed', function () {
    Poll::factory()->expired()->count(3)->create();

    $this->artisan('polls:close-expired')
        ->expectsOutputToContain('Closed 3 poll(s)')
        ->assertSuccessful();
});

it('returns success with no output when there are no expired polls', function () {
    $this->artisan('polls:close-expired')
        ->expectsOutputToContain('No expired polls found')
        ->assertSuccessful();
});
