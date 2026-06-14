<?php

namespace Tests\Feature\Contributions;

use App\Models\User;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Services\ContributionFeedSlotsService;
use App\Modules\Contributions\Services\DailyChallengeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Tests\TestCase;

class FeedAndChallengeTest extends TestCase
{
    use DatabaseTransactions;

    private function openTask(string $prompt): ContributionTask
    {
        return ContributionTask::create([
            'type' => ContributionTask::TYPE_TRANSLATE,
            'source_lang' => 'teo',
            'target_lang' => 'en',
            'region' => 'ug',
            'register' => 'lyrical',
            'prompt_text' => $prompt,
            'redundancy_target' => 3,
            'status' => ContributionTask::STATUS_OPEN,
        ]);
    }

    private function fakePage(int $n): Collection
    {
        return collect(range(1, $n))->map(fn ($i) => ['id' => $i, 'source' => 'post', 'content' => "post {$i}"]);
    }

    public function test_publish_today_creates_an_idempotent_daily_challenge(): void
    {
        $service = app(DailyChallengeService::class);

        $first = $service->publishToday();
        $second = $service->publishToday();

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('daily_challenge', $first->register);
        $this->assertSame(now()->toDateString(), $first->metadata['challenge_date']);
        $this->assertNotNull($service->today());
    }

    public function test_feed_injects_earn_cards_when_enabled(): void
    {
        config(['contributions.enabled' => true, 'contributions.feed.enabled' => true, 'contributions.feed.every' => 6, 'contributions.feed.max_per_page' => 2]);

        $this->openTask('Eong ajokis');
        $this->openTask('Apolou noi');

        $result = app(ContributionFeedSlotsService::class)
            ->injectInto($this->fakePage(12), 1, User::factory()->create());

        $earn = $result->where('feed_type', 'contribution_task');
        $this->assertCount(2, $earn);
        $this->assertSame(14, $result->count()); // 12 organic + 2 earn
    }

    public function test_feed_injection_no_ops_when_module_disabled(): void
    {
        config(['contributions.enabled' => false]);
        $this->openTask('Eong ajokis');

        $page = $this->fakePage(12);
        $result = app(ContributionFeedSlotsService::class)->injectInto($page, 1, User::factory()->create());

        $this->assertSame(12, $result->count());
        $this->assertCount(0, $result->where('feed_type', 'contribution_task'));
    }

    public function test_daily_challenge_is_prioritised_in_earn_cards(): void
    {
        config(['contributions.enabled' => true, 'contributions.feed.enabled' => true]);
        $this->openTask('a regular line');
        app(DailyChallengeService::class)->publishToday();

        $cards = app(ContributionFeedSlotsService::class)->earnCards(User::factory()->create(), 2);

        $this->assertTrue((bool) $cards->first()['is_daily_challenge']);
    }
}
