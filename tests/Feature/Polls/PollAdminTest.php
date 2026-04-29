<?php

use App\Models\Modules\Forum\Poll;
use App\Models\Modules\Forum\PollAnswer;
use App\Models\Modules\Forum\PollOption;
use App\Models\Modules\Forum\PollQuestion;
use App\Models\Modules\Forum\PollResponse;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

// ── Helpers ────────────────────────────────────────────────────────────────

function makeAdmin(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $role = Role::firstOrCreate(
        ['name' => 'admin'],
        ['display_name' => 'Admin', 'is_active' => true, 'priority' => 5]
    );
    $user->roles()->attach($role->id, ['assigned_at' => now(), 'is_active' => true]);
    cache()->forget("user:{$user->id}:roles");

    return $user;
}

function makeActivePoll(): Poll
{
    $poll = Poll::factory()->create([
        'status' => Poll::STATUS_ACTIVE,
        'audience' => Poll::AUDIENCE_ALL,
        'ends_at' => now()->addDay(),
    ]);

    $q = PollQuestion::factory()->create([
        'poll_id' => $poll->id,
        'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE,
        'is_required' => true,
    ]);

    PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'A', 'position' => 1]);
    PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'B', 'position' => 2]);

    return $poll;
}

function makeStorePollPayload(): array
{
    return [
        'title' => 'Admin Test Poll',
        'poll_type' => Poll::TYPE_GENERAL,
        'audience' => Poll::AUDIENCE_ALL,
        'status' => Poll::STATUS_ACTIVE,
        'ends_at' => now()->addDays(7)->toISOString(),
        'questions' => [
            [
                'question_text' => 'Choose an option',
                'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE,
                'is_required' => true,
                'allow_multiple' => false,
                'options' => [
                    ['option_text' => 'Alpha'],
                    ['option_text' => 'Beta'],
                ],
            ],
        ],
    ];
}

// ── Auth guards ────────────────────────────────────────────────────────────

test('guest cannot access admin polls', function () {
    $this->getJson('/api/admin/polls')->assertUnauthorized();
});

test('regular user cannot access admin polls', function () {
    $user = User::factory()->create(['is_active' => true]);
    $this->actingAs($user)->getJson('/api/admin/polls')->assertForbidden();
});

// ── GET /api/admin/polls (index) ───────────────────────────────────────────

test('admin can list all polls', function () {
    $admin = makeAdmin();
    makeActivePoll();

    $response = $this->actingAs($admin)->getJson('/api/admin/polls');

    $response->assertOk()
        ->assertJsonStructure(['data', 'meta']);
});

test('admin polls index returns all statuses including drafts', function () {
    $admin = makeAdmin();
    $draft = Poll::factory()->draft()->create();

    $response = $this->actingAs($admin)->getJson('/api/admin/polls');

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($draft->id);
});

// ── GET /api/admin/polls/stats ─────────────────────────────────────────────

test('admin can view poll stats', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->getJson('/api/admin/polls/stats')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

// ── POST /api/admin/polls (store) ──────────────────────────────────────────

test('admin can create a research survey', function () {
    $admin = makeAdmin();
    $payload = makeStorePollPayload();
    $payload['poll_type'] = Poll::TYPE_RESEARCH_SURVEY;
    $payload['questions'][0]['question_type'] = PollQuestion::TYPE_FREE_TEXT;
    unset($payload['questions'][0]['options']);

    $response = $this->actingAs($admin)->postJson('/api/admin/polls', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('polls', ['title' => 'Admin Test Poll', 'poll_type' => Poll::TYPE_RESEARCH_SURVEY]);
});

test('admin store validates required fields', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->postJson('/api/admin/polls', [])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);
});

// ── GET /api/admin/polls/{id} (show) ──────────────────────────────────────

test('admin can get a single poll by id', function () {
    $admin = makeAdmin();
    $poll = makeActivePoll();

    $this->actingAs($admin)->getJson("/api/admin/polls/{$poll->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $poll->id);
});

test('admin show returns 404 for non-existent poll', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->getJson('/api/admin/polls/999999')
        ->assertNotFound();
});

// ── PUT /api/admin/polls/{id} (update) ────────────────────────────────────

test('admin can update a poll title', function () {
    $admin = makeAdmin();
    $poll = makeActivePoll();

    $this->actingAs($admin)->putJson("/api/admin/polls/{$poll->id}", [
        'title' => 'Updated Title',
    ])->assertOk();

    $this->assertDatabaseHas('polls', ['id' => $poll->id, 'title' => 'Updated Title']);
});

// ── DELETE /api/admin/polls/{id} ──────────────────────────────────────────

test('admin can delete a poll', function () {
    $admin = makeAdmin();
    $poll = makeActivePoll();

    $this->actingAs($admin)->deleteJson("/api/admin/polls/{$poll->id}")
        ->assertOk();

    $this->assertSoftDeleted('polls', ['id' => $poll->id]);
});

// ── POST /api/admin/polls/{id}/close ──────────────────────────────────────

test('admin can close an active poll', function () {
    $admin = makeAdmin();
    $poll = makeActivePoll();

    $this->actingAs($admin)->postJson("/api/admin/polls/{$poll->id}/close")
        ->assertOk();

    $this->assertDatabaseHas('polls', ['id' => $poll->id, 'status' => Poll::STATUS_CLOSED]);
});

// ── POST /api/admin/polls/{id}/reopen ─────────────────────────────────────

test('admin can reopen a closed poll', function () {
    $admin = makeAdmin();
    $poll = Poll::factory()->create(['status' => Poll::STATUS_CLOSED]);

    $this->actingAs($admin)->postJson("/api/admin/polls/{$poll->id}/reopen")
        ->assertOk();

    $this->assertDatabaseHas('polls', ['id' => $poll->id, 'status' => Poll::STATUS_ACTIVE]);
});

// ── GET /api/admin/polls/{id}/analytics ───────────────────────────────────

test('admin can view poll analytics', function () {
    $admin = makeAdmin();
    $poll = makeActivePoll();
    $q = $poll->questions()->first();
    $opt = $q->options()->first();

    // Seed one response
    $resp = PollResponse::factory()->create(['poll_id' => $poll->id]);
    PollAnswer::create(['response_id' => $resp->id, 'question_id' => $q->id, 'option_id' => $opt->id]);

    $response = $this->actingAs($admin)->getJson("/api/admin/polls/{$poll->id}/analytics");

    $response->assertOk()
        ->assertJsonStructure(['data' => ['total_responses', 'completion_rate', 'questions']]);
});

test('admin analytics returns empty structure for poll with no responses', function () {
    $admin = makeAdmin();
    $poll = makeActivePoll();

    $response = $this->actingAs($admin)->getJson("/api/admin/polls/{$poll->id}/analytics");

    $response->assertOk();
    expect($response->json('data.total_responses'))->toBe(0);
});

// ── GET /api/admin/polls/{id}/export ──────────────────────────────────────

test('admin can export poll responses as CSV', function () {
    $admin = makeAdmin();
    $poll = makeActivePoll();
    $q = $poll->questions()->first();
    $opt = $q->options()->first();

    $resp = PollResponse::factory()->create(['poll_id' => $poll->id]);
    PollAnswer::create(['response_id' => $resp->id, 'question_id' => $q->id, 'option_id' => $opt->id]);

    $response = $this->actingAs($admin)->get("/api/admin/polls/{$poll->id}/export");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
});

test('guest cannot access admin poll export', function () {
    $poll = makeActivePoll();

    $this->get("/api/admin/polls/{$poll->id}/export")->assertUnauthorized();
});
