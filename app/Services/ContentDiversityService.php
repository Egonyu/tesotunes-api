<?php

namespace App\Services;

use Illuminate\Support\Collection;

class ContentDiversityService
{
    /**
     * Diversify content results to ensure variety.
     */
    public function diversify(array $items, string $key = 'artist_id', int $maxPerGroup = 3): array
    {
        if (empty($items)) {
            return $items;
        }

        $groups = [];
        $result = [];

        foreach ($items as $item) {
            $groupKey = is_array($item) ? ($item[$key] ?? 'unknown') : ($item->$key ?? 'unknown');
            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = 0;
            }
            if ($groups[$groupKey] < $maxPerGroup) {
                $result[] = $item;
                $groups[$groupKey]++;
            }
        }

        return $result;
    }

    /**
     * Balance items by a category key to prevent any single category from dominating.
     *
     * Uses round-robin interleaving: items are grouped by the category returned
     * by $categoryFn, then interleaved so that consecutive items are from
     * different categories when possible.
     *
     * @param  Collection  $items  The items to balance
     * @param  callable  $categoryFn  A closure that receives an item and returns its category string
     * @param  int  $limit  Maximum number of items to return
     */
    public function balanceByCategory(Collection $items, callable $categoryFn, int $limit): Collection
    {
        if ($items->isEmpty()) {
            return $items;
        }

        // Group items by category, preserving order within each group
        $groups = $items->groupBy($categoryFn);

        // Round-robin interleave
        $result = collect();
        $iterators = $groups->map(fn (Collection $group) => $group->values()->all())->all();

        $maxLen = $groups->max(fn (Collection $g) => $g->count());

        for ($i = 0; $i < $maxLen && $result->count() < $limit; $i++) {
            foreach ($iterators as $groupItems) {
                if (isset($groupItems[$i]) && $result->count() < $limit) {
                    $result->push($groupItems[$i]);
                }
            }
        }

        return $result;
    }

    /**
     * Prevent clustering by ensuring no more than $maxConsecutive items from the
     * same module/actor appear in sequence.
     *
     * @param  Collection  $items  The sorted items
     * @param  int  $maxConsecutive  Max consecutive items from same module
     */
    public function preventClustering(Collection $items, int $maxConsecutive = 3): Collection
    {
        if ($items->count() <= $maxConsecutive) {
            return $items;
        }

        $result = collect();
        $deferred = collect();
        $lastModule = null;
        $consecutive = 0;

        foreach ($items as $item) {
            $module = $item->module ?? 'unknown';

            if ($module === $lastModule) {
                $consecutive++;
                if ($consecutive >= $maxConsecutive) {
                    $deferred->push($item);

                    continue;
                }
            } else {
                $lastModule = $module;
                $consecutive = 1;
            }

            $result->push($item);
        }

        // Append deferred items at the end (they'll be spread across the tail)
        return $result->concat($deferred);
    }
}
