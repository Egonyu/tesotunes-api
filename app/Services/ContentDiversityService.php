<?php

namespace App\Services;

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
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = 0;
            }
            if ($groups[$groupKey] < $maxPerGroup) {
                $result[] = $item;
                $groups[$groupKey]++;
            }
        }

        return $result;
    }
}
