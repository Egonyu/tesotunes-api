<?php

use App\Models\Modules\Forum\Poll;
use App\Models\Modules\Forum\PollOption;
use App\Models\Modules\Forum\PollQuestion;
use App\Models\Modules\Forum\PollResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

// ── Helpers ────────────────────────────────────────────────────────────────

function makePoll(array $attrs = []): Poll
{
    return Poll::factory()->create(array_merge([
        'status' => Poll::STATUS_ACTIVE,
        'audience' => Poll::AUDIENCE_ALL,
        'ends_at' => now()->addDay(),
    ], $attrs));
}

function addQuestion(Poll $poll, string $type = PollQuestion::TYPE_MULTIPLE_CHOICE): PollQuestion
{
    return PollQuestion::factory()->create([
        'poll_id' => $poll->id,
        'question_type' => $type,
        'is_required' => true,
    ]);
}

function addOptions(PollQuestion $question, int $count = 2): \Illuminate\Support\Collection
{
    return collect(range(1, $count))->map(fn ($i) => PollOption::factory()->create([
        'question_id' => $question->id,
        'option_text' => "Option {$i}",
        'position' => $i,
    ]));
}

// ── GET /api/polls (public index) ──────────────────────────────────────────

test('guest can list active polls with audience=all', function () {
    $visible = makePoll(['audience' => Poll::AUDIENCE_ALL]);
    $hidden = makePoll(['audience' => Poll::AUDIENCE_ARTISTS]);
    addQuestion($visible);
    addQuestion($hidden);

    $response = $this->getJson('/api/polls');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($visible->id)
        ->not->toContain($hidden->id);
});

test('authenticated user sees artist-targeted polls', function () {
    $user = User::factory()->create(['is_active' => true]);
    $poll = makePoll(['audience' => Poll::AUDIENCE_ARTISTS]);
    addQuestion($poll);

    $response = $this->actingAs($user)->getJson('/api/polls');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($poll->id);
});

test('draft polls are not returned in public index', function () {
    $draft = makePoll(['status' => Poll::STATUS_DRAFT]);
    addQuestion($draft);

    $response = $this->getJson('/api/polls');

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->not->toContain($draft->id);
});

test('index filters by poll_type', function () {
    $battle = makePoll(['poll_type' => Poll::TYPE_SONG_BATTLE]);
    $general = makePoll(['poll_type' => Poll::TYPE_GENERAL]);
    addQuestion($battle);
    addQuestion($general);

    $response = $this->getJson('/api/polls?poll_type=song_battle');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($battle->id)
        ->not->toContain($general->id);
});

// ── GET /api/polls/{poll} (show) ───────────────────────────────────────────

test('guest can view an active poll', function () {
    $poll = makePoll();
    $q = addQuestion($poll);
    addOptions($q);

    $response = $this->getJson("/api/polls/{$poll->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $poll->id);
});

test('closed poll is visible', function () {
    $poll = makePoll(['status' => Poll::STATUS_CLOSED]);
    addQuestion($poll);

    $this->getJson("/api/polls/{$poll->id}")->assertOk();
});

test('draft poll returns 404', function () {
    $poll = makePoll(['status' => Poll::STATUS_DRAFT]);

    $this->getJson("/api/polls/{$poll->id}")->assertNotFound();
});

test('archived poll returns 404', function () {
    $poll = makePoll(['status' => Poll::STATUS_ARCHIVED]);

    $this->getJson("/api/polls/{$poll->id}")->assertNotFound();
});

// ── GET /api/polls/{poll}/results ──────────────────────────────────────────

test('results are forbidden before completing when show_results_before_completion is false', function () {
    $poll = makePoll(['show_results_before_completion' => false]);
    addQuestion($poll);

    $this->getJson("/api/polls/{$poll->id}/results")->assertForbidden();
});

test('results are visible when show_results_before_completion is true', function () {
    $poll = makePoll(['show_results_before_completion' => true]);
    addQuestion($poll);

    $this->getJson("/api/polls/{$poll->id}/results")->assertOk();
});

test('results are visible after user has responded', function () {
    $user = User::factory()->create(['is_active' => true]);
    $poll = makePoll(['show_results_before_completion' => false]);
    $q = addQuestion($poll);
    $opt = addOptions($q)->first();

    PollResponse::factory()->create(['poll_id' => $poll->id, 'user_id' => $user->id]);

    $this->actingAs($user)->getJson("/api/polls/{$poll->id}/results")->assertOk();
});

test('results are always visible for closed polls', function () {
    $poll = makePoll(['status' => Poll::STATUS_CLOSED, 'show_results_before_completion' => false]);
    addQuestion($poll);

    $this->getJson("/api/polls/{$poll->id}/results")->assertOk();
});

// ── POST /api/polls/{poll}/respond ─────────────────────────────────────────

test('authenticated user can respond to a multiple-choice poll', function () {
    $user = User::factory()->create(['is_active' => true]);
    $poll = makePoll(['allow_guest_responses' => true]);
    $q = addQuestion($poll);
    $opt = addOptions($q)->first();

    $response = $this->actingAs($user)->postJson("/api/polls/{$poll->id}/respond", [
        'answers' => [
            ['question_id' => $q->id, 'option_ids' => [$opt->id]],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('poll_responses', ['poll_id' => $poll->id, 'user_id' => $user->id]);
});

test('guest can respond when allow_guest_responses is true', function () {
    $poll = makePoll(['allow_guest_responses' => true]);
    $q = addQuestion($poll);
    $opt = addOptions($q)->first();

    $response = $this->postJson("/api/polls/{$poll->id}/respond", [
        'session_token' => \Illuminate\Support\Str::random(64),
        'answers' => [
            ['question_id' => $q->id, 'option_ids' => [$opt->id]],
        ],
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('poll_responses', ['poll_id' => $poll->id, 'user_id' => null]);
});

test('guest is rejected when allow_guest_responses is false', function () {
    $poll = makePoll(['allow_guest_responses' => false]);
    $q = addQuestion($poll);
    $opt = addOptions($q)->first();

    $this->postJson("/api/polls/{$poll->id}/respond", [
        'session_token' => \Illuminate\Support\Str::random(64),
        'answers' => [['question_id' => $q->id, 'option_ids' => [$opt->id]]],
    ])->assertStatus(401);
});

test('responding to an inactive poll returns 422', function () {
    $user = User::factory()->create(['is_active' => true]);
    $poll = makePoll(['status' => Poll::STATUS_CLOSED]);
    $q = addQuestion($poll);
    $opt = addOptions($q)->first();

    $this->actingAs($user)->postJson("/api/polls/{$poll->id}/respond", [
        'answers' => [['question_id' => $q->id, 'option_ids' => [$opt->id]]],
    ])->assertStatus(422);
});

test('duplicate user response is rejected', function () {
    $user = User::factory()->create(['is_active' => true]);
    $poll = makePoll();
    $q = addQuestion($poll);
    $opt = addOptions($q)->first();

    PollResponse::factory()->create(['poll_id' => $poll->id, 'user_id' => $user->id]);

    $this->actingAs($user)->postJson("/api/polls/{$poll->id}/respond", [
        'answers' => [['question_id' => $q->id, 'option_ids' => [$opt->id]]],
    ])->assertStatus(422);
});

test('missing required question returns 422', function () {
    $user = User::factory()->create(['is_active' => true]);
    $poll = makePoll();
    addQuestion($poll); // required but not answered

    $this->actingAs($user)->postJson("/api/polls/{$poll->id}/respond", [
        'answers' => [],
    ])->assertStatus(422);
});

test('user can respond to rating question', function () {
    $user = User::factory()->create(['is_active' => true]);
    $poll = makePoll();
    $q = PollQuestion::factory()->rating()->create(['poll_id' => $poll->id, 'is_required' => true]);

    $this->actingAs($user)->postJson("/api/polls/{$poll->id}/respond", [
        'answers' => [['question_id' => $q->id, 'rating_value' => 7]],
    ])->assertOk();

    $this->assertDatabaseHas('poll_answers', ['question_id' => $q->id, 'rating_value' => 7]);
});

test('user can respond to free-text question', function () {
    $user = User::factory()->create(['is_active' => true]);
    $poll = makePoll();
    $q = PollQuestion::factory()->freeText()->create(['poll_id' => $poll->id, 'is_required' => true]);

    $this->actingAs($user)->postJson("/api/polls/{$poll->id}/respond", [
        'answers' => [['question_id' => $q->id, 'answer_text' => 'Great platform!']],
    ])->assertOk();

    $this->assertDatabaseHas('poll_answers', ['question_id' => $q->id, 'answer_text' => 'Great platform!']);
});

// ── POST /api/polls (user creates poll) ────────────────────────────────────

test('authenticated user can create a community poll', function () {
    $user = User::factory()->create(['is_active' => true]);

    $response = $this->actingAs($user)->postJson('/api/polls', [
        'title' => 'Which song is better?',
        'poll_type' => Poll::TYPE_GENERAL,
        'questions' => [
            [
                'question_text' => 'Pick your favourite',
                'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE,
                'options' => [
                    ['option_text' => 'Song A'],
                    ['option_text' => 'Song B'],
                ],
            ],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('polls', ['user_id' => $user->id, 'title' => 'Which song is better?']);
});

test('guest cannot create a poll', function () {
    $this->postJson('/api/polls', [
        'title' => 'Test',
        'poll_type' => Poll::TYPE_GENERAL,
        'questions' => [['question_text' => 'Q?', 'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE]],
    ])->assertUnauthorized();
});

test('user cannot create a research survey', function () {
    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($user)->postJson('/api/polls', [
        'title' => 'Research',
        'poll_type' => Poll::TYPE_RESEARCH_SURVEY,
        'questions' => [['question_text' => 'Q?', 'question_type' => PollQuestion::TYPE_FREE_TEXT]],
    ])->assertForbidden();
});

test('creating poll without questions returns 422', function () {
    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($user)->postJson('/api/polls', [
        'title' => 'No questions',
        'poll_type' => Poll::TYPE_GENERAL,
        'questions' => [],
    ])->assertStatus(422);
});

// ── GET /api/polls/my ─────────────────────────────────────────────────────

test('my polls requires authentication', function () {
    $this->getJson('/api/polls/my')->assertUnauthorized();
});

test('my polls returns only the authenticated users own polls', function () {
    $user = User::factory()->create(['is_active' => true]);
    $other = User::factory()->create(['is_active' => true]);

    $mine = makePoll(['user_id' => $user->id]);
    $theirs = makePoll(['user_id' => $other->id]);

    $response = $this->actingAs($user)->getJson('/api/polls/my');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($mine->id)
        ->not->toContain($theirs->id);
});
