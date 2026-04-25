<?php

namespace App\Console\Commands;

use App\Models\Modules\Forum\Poll;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CloseExpiredPolls extends Command
{
    protected $signature = 'polls:close-expired';

    protected $description = 'Close all active polls whose end date has passed';

    public function handle(): int
    {
        $expired = Poll::where('status', 'active')
            ->where('ends_at', '<=', now())
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No expired polls found.');

            return self::SUCCESS;
        }

        $closed = 0;
        $failed = 0;

        foreach ($expired as $poll) {
            try {
                $poll->update(['status' => 'closed']);
                $closed++;
            } catch (\Exception $e) {
                $failed++;
                Log::error('Failed to close expired poll', [
                    'poll_id' => $poll->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Closed {$closed} poll(s).".($failed > 0 ? " {$failed} failed — see logs." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
