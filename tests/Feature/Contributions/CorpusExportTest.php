<?php

namespace Tests\Feature\Contributions;

use App\Modules\Contributions\Models\CorpusPair;
use App\Modules\Contributions\Services\CorpusExportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CorpusExportTest extends TestCase
{
    use DatabaseTransactions;

    private function pair(string $en, string $ateso): CorpusPair
    {
        return CorpusPair::create([
            'en_text' => $en,
            'ateso_text' => $ateso,
            'register' => 'lyrical',
            'region' => 'ug',
            'quality_score' => 92.5,
            'license_version' => 'CC-BY-SA-4.0',
            'provenance' => ['task_uuid' => 'abc', 'contributor_ids' => [1, 2]],
        ]);
    }

    public function test_export_writes_one_jsonl_line_per_pair_and_stamps_version(): void
    {
        Storage::fake('local');
        $this->pair('I greet you', 'Eong ajokis');
        $this->pair('Good morning', 'Ijaarakini');

        $result = app(CorpusExportService::class)->export('v-test', 'local');

        $this->assertSame(2, $result['count']);
        $this->assertSame('v-test', $result['version']);
        Storage::disk('local')->assertExists($result['path']);

        $lines = array_values(array_filter(explode("\n", Storage::disk('local')->get($result['path']))));
        $this->assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        $this->assertSame('I greet you', $first['en']);
        $this->assertSame('Eong ajokis', $first['ateso']);
        $this->assertSame('CC-BY-SA-4.0', $first['license']);
        $this->assertSame('v-test', $first['corpus_version']);
        $this->assertSame('ug', $first['region']);

        // Pairs are stamped with the version + export time.
        $this->assertSame(2, CorpusPair::where('corpus_version', 'v-test')->whereNotNull('exported_at')->count());
    }

    public function test_export_defaults_to_a_timestamped_version(): void
    {
        Storage::fake('local');
        $this->pair('Thank you', 'Eyalama');

        $result = app(CorpusExportService::class)->export(null, 'local');

        $this->assertStringStartsWith('v', $result['version']);
        $this->assertSame(1, $result['count']);
    }

    public function test_corpus_export_command_runs(): void
    {
        Storage::fake('local');
        $this->pair('Welcome', 'Imina');

        $this->artisan('corpus:export', ['--tag' => 'v-cmd', '--disk' => 'local'])
            ->assertSuccessful();

        // The command stamped the exported pair with the requested version.
        $this->assertSame(1, CorpusPair::where('corpus_version', 'v-cmd')->count());
    }
}
