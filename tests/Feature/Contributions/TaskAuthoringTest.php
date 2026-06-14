<?php

namespace Tests\Feature\Contributions;

use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Services\TaskAuthoringService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TaskAuthoringTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): TaskAuthoringService
    {
        return app(TaskAuthoringService::class);
    }

    public function test_import_creates_one_task_per_prompt_with_the_chosen_direction(): void
    {
        $result = $this->service()->import(
            ['Good morning', 'How much is this?', 'Thank you'],
            'en_to_teo',
            'conversational',
        );

        $this->assertSame(3, $result['created']);
        $this->assertSame(0, $result['skipped']);

        $task = ContributionTask::where('prompt_text', 'Good morning')->first();
        $this->assertSame('en', $task->source_lang); // show English…
        $this->assertSame('teo', $task->target_lang); // …translate to Ateso
        $this->assertSame('conversational', $task->register);
        $this->assertSame(ContributionTask::STATUS_OPEN, $task->status);
    }

    public function test_import_dedupes_within_batch_and_skips_existing(): void
    {
        $this->service()->import(['Thank you'], 'teo_to_en');

        $result = $this->service()->import(
            ['Thank you', 'Thank you ', 'Welcome'], // dup + whitespace-dup + new
            'teo_to_en',
        );

        $this->assertSame(1, $result['created']); // only "Welcome"
        $this->assertSame(1, $result['skipped']); // the existing "Thank you"
    }

    public function test_create_is_idempotent(): void
    {
        $this->service()->create('Eyalama', 'teo_to_en');
        $this->service()->create('Eyalama', 'teo_to_en');

        $this->assertSame(1, ContributionTask::where('prompt_text', 'Eyalama')->count());
    }
}
