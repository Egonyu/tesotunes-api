<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ABTestingService
{
    /**
     * Assign user to experiment variant
     */
    public function assignVariant(string $experimentName, array $variants, ?int $userId = null): string
    {
        $userId = $userId ?? auth()->id() ?? session()->getId();
        $cacheKey = "ab_test:{$experimentName}:{$userId}";
        
        // Check if user already has a variant
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        
        // Assign variant based on user ID hash
        $hash = crc32($userId . $experimentName);
        $variantIndex = $hash % count($variants);
        $variant = $variants[$variantIndex];
        
        // Store variant assignment (30 days)
        Cache::put($cacheKey, $variant, 2592000);
        
        // Track assignment
        $this->trackAssignment($experimentName, $variant, $userId);
        
        return $variant;
    }
    
    /**
     * Check if user is in specific variant
     */
    public function isVariant(string $experimentName, string $variant): bool
    {
        $userVariant = $this->getUserVariant($experimentName);
        return $userVariant === $variant;
    }
    
    /**
     * Get user's assigned variant
     */
    public function getUserVariant(string $experimentName): ?string
    {
        $userId = auth()->id() ?? session()->getId();
        $cacheKey = "ab_test:{$experimentName}:{$userId}";
        
        return Cache::get($cacheKey);
    }
    
    /**
     * Track conversion event
     */
    public function trackConversion(string $experimentName, string $goalName, $value = null): void
    {
        $variant = $this->getUserVariant($experimentName);
        
        if (!$variant) {
            return;
        }
        
        $key = "ab_test:conversions:{$experimentName}:{$variant}:{$goalName}:" . now()->format('Y-m-d');
        
        $conversions = Cache::get($key, [
            'count' => 0,
            'total_value' => 0,
        ]);
        
        $conversions['count']++;
        if ($value !== null) {
            $conversions['total_value'] += $value;
        }
        
        Cache::put($key, $conversions, 86400 * 30); // 30 days
    }
    
    /**
     * Get experiment results
     */
    public function getExperimentResults(string $experimentName): array
    {
        $results = [
            'experiment' => $experimentName,
            'variants' => [],
        ];
        
        // Get all variants
        $pattern = "ab_test:assignments:{$experimentName}:*";
        $assignments = Cache::get($pattern, []);
        
        foreach ($assignments as $variant => $count) {
            $conversions = $this->getVariantConversions($experimentName, $variant);
            
            $results['variants'][$variant] = [
                'assignments' => $count,
                'conversions' => $conversions,
                'conversion_rate' => $count > 0 ? ($conversions['total_count'] / $count) * 100 : 0,
            ];
        }
        
        return $results;
    }
    
    /**
     * Get variant conversions
     */
    private function getVariantConversions(string $experimentName, string $variant): array
    {
        $pattern = "ab_test:conversions:{$experimentName}:{$variant}:*";
        $keys = Cache::get($pattern, []);
        
        $totalCount = 0;
        $totalValue = 0;
        $byGoal = [];
        
        foreach ($keys as $key) {
            $data = Cache::get($key);
            $goalName = explode(':', $key)[4] ?? 'unknown';
            
            $totalCount += $data['count'];
            $totalValue += $data['total_value'];
            
            $byGoal[$goalName] = [
                'count' => $data['count'],
                'value' => $data['total_value'],
            ];
        }
        
        return [
            'total_count' => $totalCount,
            'total_value' => $totalValue,
            'by_goal' => $byGoal,
        ];
    }
    
    /**
     * Track assignment
     */
    private function trackAssignment(string $experimentName, string $variant, $userId): void
    {
        $key = "ab_test:assignments:{$experimentName}:{$variant}";
        
        $count = Cache::get($key, 0);
        Cache::put($key, $count + 1, 86400 * 30); // 30 days
    }
    
    /**
     * Calculate statistical significance
     */
    public function calculateSignificance(array $variantA, array $variantB): array
    {
        $n1 = $variantA['assignments'];
        $n2 = $variantB['assignments'];
        $p1 = $variantA['conversion_rate'] / 100;
        $p2 = $variantB['conversion_rate'] / 100;
        
        if ($n1 == 0 || $n2 == 0) {
            return [
                'significant' => false,
                'confidence' => 0,
                'p_value' => 1,
            ];
        }
        
        // Calculate pooled probability
        $p = (($p1 * $n1) + ($p2 * $n2)) / ($n1 + $n2);
        
        // Calculate standard error
        $se = sqrt($p * (1 - $p) * ((1 / $n1) + (1 / $n2)));
        
        // Calculate z-score
        $z = ($p1 - $p2) / $se;
        
        // Calculate p-value (simplified)
        $pValue = 2 * (1 - $this->normalCDF(abs($z)));
        
        // Check significance at 95% confidence level
        $significant = $pValue < 0.05;
        $confidence = (1 - $pValue) * 100;
        
        return [
            'significant' => $significant,
            'confidence' => round($confidence, 2),
            'p_value' => round($pValue, 4),
            'winner' => $significant ? ($p1 > $p2 ? 'A' : 'B') : null,
        ];
    }
    
    /**
     * Normal cumulative distribution function (simplified)
     */
    private function normalCDF(float $x): float
    {
        // Approximation using error function
        return 0.5 * (1 + $this->erf($x / sqrt(2)));
    }
    
    /**
     * Error function (simplified approximation)
     */
    private function erf(float $x): float
    {
        // Abramowitz and Stegun approximation
        $a1 =  0.254829592;
        $a2 = -0.284496736;
        $a3 =  1.421413741;
        $a4 = -1.453152027;
        $a5 =  1.061405429;
        $p  =  0.3275911;
        
        $sign = $x < 0 ? -1 : 1;
        $x = abs($x);
        
        $t = 1.0 / (1.0 + $p * $x);
        $y = 1.0 - ((((($a5 * $t + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);
        
        return $sign * $y;
    }
}
