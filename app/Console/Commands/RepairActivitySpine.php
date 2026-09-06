<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ops repair for the activity spine.
 *
 * Activity had timestamps disabled while its table declared them, so only the
 * writers that passed created_at by hand produced a dated row. Everything else
 * landed NULL, dropped out of the feed's `latest()` ordering, and rendered a
 * blank date on the dashboard. Separately, four writers spelled their activity
 * type differently from the vocabulary FeedItemFactory maps, so those rows
 * never became feed items at all.
 *
 * The code is fixed; this repairs the rows already written. It is deliberately
 * an ops command, not a migration — data repair is not schema.
 */
class RepairActivitySpine extends Command
{
    protected $signature = 'activities:repair-spine
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Backfill missing activity timestamps, normalise legacy type names, and remove duplicate activities';

    /**
     * Legacy type => the spelling FeedItemFactory actually maps.
     *
     * @var array<string, string>
     */
    private const TYPE_RENAMES = [
        'commented_on_song' => 'commented_song',
        'commented_on_album' => 'commented_album',
        'commented_on_artist' => 'commented_artist',
        'created_store' => 'store_created',
        'listed_product' => 'product_listed',
    ];

    /**
     * Activity type prefix => the table whose row dates it precisely, and the
     * columns tying that row back to the activity.
     *
     * @var array<string, array{table: string, type_column: string, id_column: string}>
     */
    private const PRECISE_SOURCES = [
        'liked_' => ['table' => 'likes', 'type_column' => 'likeable_type', 'id_column' => 'likeable_id'],
        'unliked_' => ['table' => 'likes', 'type_column' => 'likeable_type', 'id_column' => 'likeable_id'],
        'commented_' => ['table' => 'comments', 'type_column' => 'commentable_type', 'id_column' => 'commentable_id'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — nothing will be written.');
        }

        $this->renameLegacyTypes($dryRun);
        $this->backfillTimestamps($dryRun);
        $this->removeDuplicates($dryRun);

        return self::SUCCESS;
    }

    /**
     * Like and Comment were each logged twice — once by the model or controller
     * and again by the observer watching it. One writer owns each event now;
     * this clears the rows the other one already wrote.
     *
     * Two activities for the same actor, verb, subject and instant are the same
     * event, so the oldest row wins. A duplicate carrying its own likes or
     * comments is left alone and reported: deleting it would take real
     * engagement with it.
     */
    private function removeDuplicates(bool $dryRun): void
    {
        $groups = DB::table('activities')
            ->selectRaw('user_id, type, subject_type, subject_id, created_at, COUNT(*) as total, MIN(id) as keep_id')
            ->groupBy('user_id', 'type', 'subject_type', 'subject_id', 'created_at')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->info("Removing duplicate activities across {$groups->count()} groups…");

        $deleted = 0;
        $kept = 0;

        foreach ($groups as $group) {
            $redundant = DB::table('activities')
                ->where('user_id', $group->user_id)
                ->where('type', $group->type)
                ->where('subject_type', $group->subject_type)
                ->where('subject_id', $group->subject_id)
                ->where('created_at', $group->created_at)
                ->where('id', '!=', $group->keep_id)
                ->get(['id', 'like_count', 'comments_count']);

            foreach ($redundant as $row) {
                $hasEngagement = (int) ($row->like_count ?? 0) > 0
                    || (int) ($row->comments_count ?? 0) > 0;

                if ($hasEngagement) {
                    $kept++;

                    continue;
                }

                if (! $dryRun) {
                    DB::table('activities')->where('id', $row->id)->delete();
                }

                $deleted++;
            }
        }

        $this->line("  redundant rows removed:                    {$deleted}");
        $this->line("  kept because they carry engagement:        {$kept}");
    }

    private function renameLegacyTypes(bool $dryRun): void
    {
        $this->info('Normalising activity type names…');

        foreach (self::TYPE_RENAMES as $legacy => $canonical) {
            $count = DB::table('activities')->where('type', $legacy)->count();

            if ($count === 0) {
                continue;
            }

            $this->line("  {$legacy} → {$canonical} ({$count} rows)");

            if (! $dryRun) {
                DB::table('activities')->where('type', $legacy)->update(['type' => $canonical]);
            }
        }

        /**
         * Like wrote its type as 'liked_'.class_basename(), so subjects with a
         * multi-word class name landed in StudlyCase. MySQL's default collation
         * hides these behind a case-insensitive comparison, so they have to be
         * found with a binary one.
         */
        $mixedCase = DB::table('activities')
            ->whereRaw('BINARY type <> LOWER(type)')
            ->pluck('type')
            ->unique();

        foreach ($mixedCase as $type) {
            $lowered = strtolower((string) $type);
            $this->line("  {$type} → {$lowered}");

            if (! $dryRun) {
                DB::table('activities')
                    ->whereRaw('BINARY type = ?', [$type])
                    ->update(['type' => $lowered]);
            }
        }
    }

    private function backfillTimestamps(bool $dryRun): void
    {
        $undated = DB::table('activities')->whereNull('created_at')->count();

        $this->info("Backfilling timestamps for {$undated} undated activities…");

        if ($undated === 0) {
            return;
        }

        $precise = 0;
        $fromSubject = 0;
        $unresolved = 0;

        DB::table('activities')
            ->whereNull('created_at')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$precise, &$fromSubject, &$unresolved, $dryRun) {
                foreach ($rows as $row) {
                    $at = $this->preciseDate($row);

                    if ($at !== null) {
                        $precise++;
                    } else {
                        $at = $this->subjectDate($row);

                        if ($at !== null) {
                            $fromSubject++;
                        }
                    }

                    if ($at === null) {
                        $unresolved++;

                        continue;
                    }

                    if (! $dryRun) {
                        DB::table('activities')
                            ->where('id', $row->id)
                            ->update(['created_at' => $at, 'updated_at' => $at]);
                    }
                }
            });

        $this->line("  dated from the source like/comment row: {$precise}");
        $this->line("  dated from the subject record:          {$fromSubject}");
        $this->line("  left undated (no source to date them):  {$unresolved}");
    }

    /**
     * The like or comment row the activity was written for carries the exact
     * moment the action happened.
     */
    private function preciseDate(object $row): ?string
    {
        foreach (self::PRECISE_SOURCES as $prefix => $source) {
            if (! str_starts_with((string) $row->type, $prefix)) {
                continue;
            }

            return DB::table($source['table'])
                ->where('user_id', $row->user_id)
                ->where($source['type_column'], $row->subject_type)
                ->where($source['id_column'], $row->subject_id)
                ->orderBy('created_at')
                ->value('created_at');
        }

        return null;
    }

    /**
     * Failing that, an activity cannot predate the thing it is about, and for
     * creation events (a song uploaded, a store opened) the two are the same
     * moment.
     */
    private function subjectDate(object $row): ?string
    {
        $class = $row->subject_type;

        if (! $class || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $model = new $class;

        if (! in_array('created_at', $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable()), true)) {
            return null;
        }

        return DB::table($model->getTable())
            ->where($model->getKeyName(), $row->subject_id)
            ->value('created_at');
    }
}
