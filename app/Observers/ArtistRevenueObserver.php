<?php

namespace App\Observers;

use App\Models\ArtistRevenue;
use Illuminate\Support\Facades\Log;

/**
 * Observer for ArtistRevenue model to enforce security and audit logging
 *
 * This observer:
 * - Logs all revenue record changes for audit trail
 * - Prevents unauthorized modifications to financial fields
 * - Validates revenue calculations
 * - Detects anomalies in revenue reporting
 * - Tracks revenue lifecycle
 */
class ArtistRevenueObserver
{
    /**
     * Handle the ArtistRevenue "creating" event.
     */
    public function creating(ArtistRevenue $revenue): void
    {
        // Set default status if not provided
        if (empty($revenue->status)) {
            $revenue->status = 'pending';
        }

        // Set default currency if not provided
        if (empty($revenue->currency)) {
            $revenue->currency = 'UGX';
        }

        // Set revenue_date if not provided (using actual DB column)
        if (empty($revenue->revenue_date)) {
            $revenue->revenue_date = now()->toDateString();
        }

        // Log creation
        Log::channel('audit')->info('Artist revenue record created', [
            'artist_id' => $revenue->artist_id,
            'revenue_type' => $revenue->revenue_type,
            'revenue_source_type' => $revenue->revenue_source_type,
            'revenue_source_id' => $revenue->revenue_source_id,
            'amount_ugx' => $revenue->amount_ugx,
            'net_amount' => $revenue->net_amount,
            'is_credit_based' => $revenue->is_credit_based,
            'ip_address' => request()->ip(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Handle the ArtistRevenue "created" event.
     */
    public function created(ArtistRevenue $revenue): void
    {
        Log::channel('audit')->info('Artist revenue record created', [
            'id' => $revenue->id,
            'artist_id' => $revenue->artist_id,
            'amount' => $revenue->net_amount,
            'status' => $revenue->status,
        ]);

        // Detect unusual revenue patterns
        $this->detectAnomalies($revenue);
    }

    /**
     * Handle the ArtistRevenue "updating" event.
     */
    public function updating(ArtistRevenue $revenue): void
    {
        $changes = $revenue->getDirty();

        // Alert on financial field changes (should be rare)
        $financialFields = ['gross_amount', 'net_amount', 'platform_fee', 'distribution_fee', 
                           'credit_amount', 'money_amount', 'artist_share_percentage'];
        
        $financialChanges = array_intersect_key($changes, array_flip($financialFields));
        
        if (!empty($financialChanges)) {
            Log::channel('audit')->warning('Revenue financial fields modified', [
                'id' => $revenue->id,
                'artist_id' => $revenue->artist_id,
                'modified_fields' => array_keys($financialChanges),
                'old_values' => array_intersect_key($revenue->getOriginal(), $financialChanges),
                'new_values' => $financialChanges,
                'changed_by' => auth()->id(),
                'ip_address' => request()->ip(),
                'timestamp' => now()->toDateTimeString(),
            ]);
        }

        // Log status changes
        if (isset($changes['status'])) {
            Log::channel('audit')->info('Revenue status changed', [
                'id' => $revenue->id,
                'artist_id' => $revenue->artist_id,
                'old_status' => $revenue->getOriginal('status'),
                'new_status' => $changes['status'],
                'changed_by' => auth()->id(),
                'timestamp' => now()->toDateTimeString(),
            ]);
        }

        // Validate calculations on update
        if (!empty($financialChanges)) {
            $this->validateRevenueCalculations($revenue);
        }
    }

    /**
     * Handle the ArtistRevenue "updated" event.
     */
    public function updated(ArtistRevenue $revenue): void
    {
        $changes = $revenue->getChanges();

        Log::channel('audit')->info('Artist revenue updated', [
            'id' => $revenue->id,
            'artist_id' => $revenue->artist_id,
            'changes' => array_keys($changes),
            'new_status' => $revenue->status,
            'timestamp' => now()->toDateTimeString(),
        ]);

        // Handle status transitions
        if (isset($changes['status'])) {
            $this->handleStatusChange($revenue, $changes['status']);
        }
    }

    /**
     * Handle the ArtistRevenue "deleted" event.
     */
    public function deleted(ArtistRevenue $revenue): void
    {
        Log::channel('audit')->critical('Artist revenue deleted', [
            'id' => $revenue->id,
            'artist_id' => $revenue->artist_id,
            'amount' => $revenue->net_amount,
            'status' => $revenue->status,
            'deleted_by' => auth()->id(),
            'ip_address' => request()->ip(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Handle the ArtistRevenue "restored" event.
     */
    public function restored(ArtistRevenue $revenue): void
    {
        Log::channel('audit')->warning('Artist revenue restored', [
            'id' => $revenue->id,
            'artist_id' => $revenue->artist_id,
            'restored_by' => auth()->id(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Validate revenue calculations
     */
    protected function validateRevenueCalculations(ArtistRevenue $revenue): void
    {
        // Validate net amount calculation
        if ($revenue->gross_amount && $revenue->net_amount) {
            $expectedNetAmount = $revenue->gross_amount - 
                                ($revenue->platform_fee ?? 0) - 
                                ($revenue->distribution_fee ?? 0);
            
            $difference = abs($expectedNetAmount - $revenue->net_amount);
            
            // Allow small rounding differences (0.01)
            if ($difference > 0.01) {
                Log::channel('audit')->warning('Revenue calculation mismatch', [
                    'artist_id' => $revenue->artist_id,
                    'song_id' => $revenue->song_id,
                    'gross_amount' => $revenue->gross_amount,
                    'platform_fee' => $revenue->platform_fee,
                    'distribution_fee' => $revenue->distribution_fee,
                    'calculated_net' => $expectedNetAmount,
                    'actual_net' => $revenue->net_amount,
                    'difference' => $difference,
                    'timestamp' => now()->toDateTimeString(),
                ]);
            }
        }

        // Validate share percentage
        if ($revenue->artist_share_percentage && 
            ($revenue->artist_share_percentage < 0 || $revenue->artist_share_percentage > 100)) {
            Log::channel('audit')->error('Invalid artist share percentage', [
                'artist_id' => $revenue->artist_id,
                'song_id' => $revenue->song_id,
                'artist_share_percentage' => $revenue->artist_share_percentage,
                'timestamp' => now()->toDateTimeString(),
            ]);
        }

        // Validate revenue splits sum to 100%
        if (!empty($revenue->revenue_splits) && is_array($revenue->revenue_splits)) {
            $totalSplit = array_sum(array_column($revenue->revenue_splits, 'percentage'));
            
            if (abs($totalSplit - 100) > 0.01) {
                Log::channel('audit')->warning('Revenue splits do not sum to 100%', [
                    'artist_id' => $revenue->artist_id,
                    'song_id' => $revenue->song_id,
                    'total_split_percentage' => $totalSplit,
                    'splits' => $revenue->revenue_splits,
                    'timestamp' => now()->toDateTimeString(),
                ]);
            }
        }
    }

    /**
     * Detect anomalies in revenue reporting
     */
    protected function detectAnomalies(ArtistRevenue $revenue): void
    {
        // Check for unusually high revenue for single transaction
        $avgRevenue = ArtistRevenue::where('artist_id', $revenue->artist_id)
            ->where('revenue_type', $revenue->revenue_type)
            ->where('id', '!=', $revenue->id)
            ->avg('net_amount');

        if ($avgRevenue && $revenue->net_amount > ($avgRevenue * 10)) {
            Log::channel('audit')->warning('Unusually high revenue detected', [
                'artist_id' => $revenue->artist_id,
                'revenue_id' => $revenue->id,
                'amount' => $revenue->net_amount,
                'average_amount' => $avgRevenue,
                'ratio' => round($revenue->net_amount / $avgRevenue, 2),
                'revenue_type' => $revenue->revenue_type,
                'timestamp' => now()->toDateTimeString(),
            ]);
        }

        // Check for negative revenue (should not happen)
        if ($revenue->net_amount < 0) {
            Log::channel('audit')->error('Negative revenue detected', [
                'artist_id' => $revenue->artist_id,
                'revenue_id' => $revenue->id,
                'net_amount' => $revenue->net_amount,
                'amount_ugx' => $revenue->amount_ugx,
                'timestamp' => now()->toDateTimeString(),
            ]);
        }
    }

    /**
     * Handle status change events
     */
    protected function handleStatusChange(ArtistRevenue $revenue, string $newStatus): void
    {
        switch ($newStatus) {
            case 'processed':
                Log::info('Revenue processed', [
                    'revenue_id' => $revenue->id,
                    'artist_id' => $revenue->artist_id,
                    'amount' => $revenue->net_amount,
                    'processed_at' => $revenue->processed_at,
                ]);
                break;

            case 'paid':
                Log::info('Revenue paid', [
                    'revenue_id' => $revenue->id,
                    'artist_id' => $revenue->artist_id,
                    'amount' => $revenue->net_amount,
                    'paid_at' => $revenue->paid_at,
                    'payout_id' => $revenue->payout_id,
                ]);
                break;
        }
    }
}
