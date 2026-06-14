<?php

namespace Tests\Feature\Contributions;

use App\Models\Artist;
use App\Models\Song;
use App\Models\User;
use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Models\SongLyricOptIn;
use App\Modules\Contributions\Services\ConsentService;
use App\Modules\Contributions\Services\LyricOptInService;
use App\Modules\Contributions\Services\SubmissionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LyricsTranslationFlowTest extends TestCase
{
    use DatabaseTransactions;

    private function optIns(): LyricOptInService
    {
        return app(LyricOptInService::class);
    }

    private function submissions(): SubmissionService
    {
        return app(SubmissionService::class);
    }

    private function consentedUser(): User
    {
        $user = User::factory()->create();
        app(ConsentService::class)->recordConsent($user);

        return $user;
    }

    private function songWithLyrics(string $lyrics): Song
    {
        $artist = Artist::factory()->create(['status' => Artist::STATUS_APPROVED]);

        return Song::factory()->create([
            'artist_id' => $artist->id,
            'status' => 'published',
            'lyrics' => $lyrics,
            'primary_language' => 'teo',
        ]);
    }

    public function test_opt_in_generates_one_task_per_distinct_lyric_line(): void
    {
        // Two distinct lines plus a duplicate and a blank line.
        $song = $this->songWithLyrics("Eong ajokis\nApolou noi\n\nEong ajokis\n");
        $artistUser = $song->artist->user ?? User::factory()->create();

        $optIn = $this->optIns()->optIn($song, $artistUser);

        $this->assertSame(SongLyricOptIn::STATUS_ACTIVE, $optIn->status);
        $this->assertSame(2, $optIn->tasks_generated);
        $this->assertSame(2, ContributionTask::query()
            ->where('source_type', $song->getMorphClass())
            ->where('source_id', $song->id)
            ->count());
    }

    public function test_opt_in_is_idempotent(): void
    {
        $song = $this->songWithLyrics("Line one\nLine two");
        $user = User::factory()->create();

        $this->optIns()->optIn($song, $user);
        $this->optIns()->optIn($song, $user); // re-opt

        $this->assertSame(2, ContributionTask::query()
            ->where('source_type', $song->getMorphClass())
            ->where('source_id', $song->id)
            ->count());
    }

    public function test_submission_requires_consent(): void
    {
        $song = $this->songWithLyrics('Eong ajokis');
        $this->optIns()->optIn($song, User::factory()->create());
        $task = ContributionTask::query()->where('source_id', $song->id)->firstOrFail();

        $noConsent = User::factory()->create();
        $this->expectException(\DomainException::class);
        $this->submissions()->submit($noConsent, $task, 'I am fine');
    }

    public function test_contributor_cannot_submit_twice_to_one_task(): void
    {
        $song = $this->songWithLyrics('Eong ajokis');
        $this->optIns()->optIn($song, User::factory()->create());
        $task = ContributionTask::query()->where('source_id', $song->id)->firstOrFail();

        $user = $this->consentedUser();
        $this->submissions()->submit($user, $task, 'I am fine');

        $this->expectException(\DomainException::class);
        $this->submissions()->submit($user, $task, 'I am well');
    }

    public function test_task_is_fulfilled_once_redundancy_target_is_met(): void
    {
        config(['contributions.redundancy_target' => 3]);
        $song = $this->songWithLyrics('Eong ajokis');
        $this->optIns()->optIn($song, User::factory()->create());
        $task = ContributionTask::query()->where('source_id', $song->id)->firstOrFail();

        foreach (range(1, 3) as $i) {
            $this->submissions()->submit($this->consentedUser(), $task, "translation {$i}");
        }

        $task->refresh();
        $this->assertSame(3, $task->submission_count);
        $this->assertSame(ContributionTask::STATUS_FULFILLED, $task->status);
        $this->assertSame(3, ContributionSubmission::where('contribution_task_id', $task->id)->count());
    }

    public function test_withdrawing_closes_open_tasks(): void
    {
        $song = $this->songWithLyrics("Line one\nLine two");
        $user = User::factory()->create();
        $this->optIns()->optIn($song, $user);

        $optIn = $this->optIns()->withdraw($song);

        $this->assertSame(SongLyricOptIn::STATUS_WITHDRAWN, $optIn->status);
        $this->assertSame(0, ContributionTask::query()
            ->where('source_id', $song->id)
            ->where('status', ContributionTask::STATUS_OPEN)
            ->count());
    }
}
