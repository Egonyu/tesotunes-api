<?php

namespace App\Services;

use App\Models\SaccoDividend;
use App\Models\SaccoMember;
use App\Models\SaccoMemberDividend;
use App\Models\SaccoAccount;
use App\Models\SaccoAuditLog;
use Illuminate\Support\Facades\DB;
use Exception;

class SaccoDividendService
{
    protected SaccoTransactionService $transactionService;

    public function __construct(SaccoTransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * Declare annual dividend
     * 
     * @param array $data
     * @return SaccoDividend
     * @throws Exception
     */
    public function declareDividend(array $data): SaccoDividend
    {
        // Validation
        $year = $data['dividend_year'];
        
        if (SaccoDividend::where('dividend_year', $year)->exists()) {
            throw new Exception("Dividend for year {$year} has already been declared.");
        }

        if ($data['total_profit'] <= 0) {
            throw new Exception('Total profit must be greater than zero.');
        }

        if ($data['dividend_rate'] <= 0 || $data['dividend_rate'] > 100) {
            throw new Exception('Dividend rate must be between 0 and 100 percent.');
        }

        DB::beginTransaction();
        
        try {
            // Create dividend declaration
            $dividend = SaccoDividend::create([
                'dividend_year' => $year,
                'total_profit' => $data['total_profit'],
                'dividend_rate' => $data['dividend_rate'],
                'declaration_date' => $data['declaration_date'] ?? now(),
                'payment_date' => $data['payment_date'] ?? now()->addDays(30),
                'status' => SaccoDividend::STATUS_DECLARED,
            ]);
            
            // Calculate and create member dividends
            $this->calculateMemberDividends($dividend);
            
            // Log declaration
            SaccoAuditLog::log(
                action: SaccoAuditLog::ACTION_CREATED,
                modelType: SaccoDividend::class,
                modelId: $dividend->id,
                newValues: $dividend->toArray()
            );
            
            DB::commit();
            
            return $dividend->fresh(['memberDividends']);
            
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Calculate dividends for all eligible members
     * 
     * @param SaccoDividend $dividend
     * @return int Number of members processed
     */
    private function calculateMemberDividends(SaccoDividend $dividend): int
    {
        // Get all active members with shares
        $members = SaccoMember::active()
            ->where('total_shares', '>', 0)
            ->get();
        
        $count = 0;
        
        foreach ($members as $member) {
            $sharesAmount = $member->total_shares;
            $dividendAmount = $dividend->calculateMemberDividend($sharesAmount);
            
            if ($dividendAmount > 0) {
                SaccoMemberDividend::create([
                    'dividend_id' => $dividend->id,
                    'member_id' => $member->id,
                    'shares_amount' => $sharesAmount,
                    'dividend_amount' => $dividendAmount, // Protected - set explicitly
                    'status' => 'pending',
                ]);
                
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Distribute dividends to members
     * 
     * @param SaccoDividend $dividend
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function distributeDividends(SaccoDividend $dividend, array $options = []): array
    {
        if ($dividend->status !== SaccoDividend::STATUS_DECLARED) {
            throw new Exception('Only declared dividends can be distributed. Current status: ' . $dividend->status);
        }

        $autoDeposit = $options['auto_deposit'] ?? true;
        
        DB::beginTransaction();
        
        try {
            $memberDividends = $dividend->memberDividends()
                ->where('status', 'pending')
                ->with('member')
                ->get();
            
            $stats = [
                'total_processed' => 0,
                'total_amount' => 0,
                'successful' => 0,
                'failed' => 0,
                'errors' => [],
            ];
            
            foreach ($memberDividends as $memberDividend) {
                try {
                    $member = $memberDividend->member;
                    
                    if ($autoDeposit) {
                        // Deposit to member's shares account
                        $sharesAccount = $member->accounts()
                            ->where('account_type', SaccoAccount::TYPE_SHARES)
                            ->active()
                            ->first();
                        
                        if ($sharesAccount) {
                            $this->transactionService->deposit(
                                $sharesAccount,
                                $memberDividend->dividend_amount,
                                "Dividend for {$dividend->dividend_year}: {$dividend->dividend_rate}% on shares",
                                ['dividend_id' => $dividend->id]
                            );
                        }
                    }
                    
                    // Mark as paid
                    $memberDividend->status = 'paid';
                    $memberDividend->paid_at = now();
                    $memberDividend->save();
                    
                    $stats['successful']++;
                    $stats['total_amount'] += $memberDividend->dividend_amount;
                    
                } catch (Exception $e) {
                    $stats['failed']++;
                    $stats['errors'][] = [
                        'member_id' => $memberDividend->member_id,
                        'error' => $e->getMessage(),
                    ];
                }
                
                $stats['total_processed']++;
            }
            
            // Update dividend status if all distributed
            if ($stats['failed'] === 0) {
                $dividend->status = SaccoDividend::STATUS_PAID;
                $dividend->save();
            }
            
            // Log distribution
            SaccoAuditLog::log(
                action: 'distributed',
                modelType: SaccoDividend::class,
                modelId: $dividend->id,
                newValues: $stats
            );
            
            DB::commit();
            
            return $stats;
            
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Pay specific member dividend
     * 
     * @param SaccoMemberDividend $memberDividend
     * @param bool $autoDeposit
     * @return SaccoMemberDividend
     * @throws Exception
     */
    public function payMemberDividend(
        SaccoMemberDividend $memberDividend,
        bool $autoDeposit = true
    ): SaccoMemberDividend {
        if ($memberDividend->status !== 'pending') {
            throw new Exception('This dividend has already been paid or cancelled.');
        }

        DB::beginTransaction();
        
        try {
            $member = $memberDividend->member;
            
            if ($autoDeposit) {
                // Deposit to shares account
                $sharesAccount = $member->accounts()
                    ->where('account_type', SaccoAccount::TYPE_SHARES)
                    ->active()
                    ->first();
                
                if (!$sharesAccount) {
                    throw new Exception('No active shares account found for member.');
                }
                
                $this->transactionService->deposit(
                    $sharesAccount,
                    $memberDividend->dividend_amount,
                    "Dividend payment for {$memberDividend->dividend->dividend_year}",
                    ['member_dividend_id' => $memberDividend->id]
                );
            }
            
            // Mark as paid
            $memberDividend->status = 'paid';
            $memberDividend->paid_at = now();
            $memberDividend->save();
            
            // Log payment
            SaccoAuditLog::log(
                action: 'paid',
                modelType: SaccoMemberDividend::class,
                modelId: $memberDividend->id,
                newValues: $memberDividend->toArray()
            );
            
            DB::commit();
            
            return $memberDividend->fresh();
            
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get dividend summary for a year
     * 
     * @param int $year
     * @return array
     */
    public function getDividendSummary(int $year): array
    {
        $dividend = SaccoDividend::where('dividend_year', $year)->first();
        
        if (!$dividend) {
            return [
                'declared' => false,
                'year' => $year,
            ];
        }
        
        $memberDividends = $dividend->memberDividends;
        
        return [
            'declared' => true,
            'year' => $year,
            'total_profit' => $dividend->total_profit,
            'dividend_rate' => $dividend->dividend_rate,
            'declaration_date' => $dividend->declaration_date,
            'payment_date' => $dividend->payment_date,
            'status' => $dividend->status,
            'total_members' => $memberDividends->count(),
            'total_amount_declared' => $memberDividends->sum('dividend_amount'),
            'total_paid' => $memberDividends->where('status', 'paid')->sum('dividend_amount'),
            'total_pending' => $memberDividends->where('status', 'pending')->sum('dividend_amount'),
            'members_paid' => $memberDividends->where('status', 'paid')->count(),
            'members_pending' => $memberDividends->where('status', 'pending')->count(),
        ];
    }

    /**
     * Get member dividend history
     * 
     * @param SaccoMember $member
     * @return array
     */
    public function getMemberDividendHistory(SaccoMember $member): array
    {
        $dividends = $member->dividends()
            ->with('dividend')
            ->orderBy('created_at', 'desc')
            ->get();
        
        return [
            'total_earned' => $dividends->where('status', 'paid')->sum('dividend_amount'),
            'total_pending' => $dividends->where('status', 'pending')->sum('dividend_amount'),
            'dividends_received' => $dividends->where('status', 'paid')->count(),
            'history' => $dividends->map(function ($memberDividend) {
                return [
                    'year' => $memberDividend->dividend->dividend_year,
                    'shares_amount' => $memberDividend->shares_amount,
                    'dividend_rate' => $memberDividend->dividend->dividend_rate,
                    'dividend_amount' => $memberDividend->dividend_amount,
                    'status' => $memberDividend->status,
                    'paid_at' => $memberDividend->paid_at,
                ];
            }),
        ];
    }

    /**
     * Project next year's dividend
     * 
     * @param float $estimatedProfit
     * @param float $dividendRate
     * @return array
     */
    public function projectDividend(float $estimatedProfit, float $dividendRate): array
    {
        $activeMembers = SaccoMember::active()->where('total_shares', '>', 0)->get();
        
        $totalShares = $activeMembers->sum('total_shares');
        $projectedDividends = [];
        $totalProjected = 0;
        
        foreach ($activeMembers as $member) {
            $projectedAmount = ($member->total_shares * $dividendRate) / 100;
            $projectedDividends[] = [
                'member_id' => $member->id,
                'member_name' => $member->user->name,
                'shares' => $member->total_shares,
                'projected_dividend' => $projectedAmount,
            ];
            $totalProjected += $projectedAmount;
        }
        
        return [
            'estimated_profit' => $estimatedProfit,
            'dividend_rate' => $dividendRate,
            'total_shares' => $totalShares,
            'eligible_members' => $activeMembers->count(),
            'total_projected_payout' => $totalProjected,
            'coverage_ratio' => $estimatedProfit > 0 ? ($totalProjected / $estimatedProfit) * 100 : 0,
            'projections' => $projectedDividends,
        ];
    }

    /**
     * Cancel dividend declaration
     * 
     * @param SaccoDividend $dividend
     * @param string $reason
     * @return SaccoDividend
     * @throws Exception
     */
    public function cancelDividend(SaccoDividend $dividend, string $reason): SaccoDividend
    {
        if ($dividend->status === SaccoDividend::STATUS_PAID) {
            throw new Exception('Cannot cancel a dividend that has already been paid.');
        }

        $paidCount = $dividend->memberDividends()->where('status', 'paid')->count();
        if ($paidCount > 0) {
            throw new Exception("Cannot cancel dividend. {$paidCount} member(s) have already been paid.");
        }

        DB::beginTransaction();
        
        try {
            $oldValues = $dividend->toArray();
            
            $dividend->status = SaccoDividend::STATUS_CANCELLED;
            $dividend->save();
            
            // Cancel all pending member dividends
            $dividend->memberDividends()
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);
            
            SaccoAuditLog::log(
                action: 'cancelled',
                modelType: SaccoDividend::class,
                modelId: $dividend->id,
                oldValues: $oldValues,
                newValues: array_merge($dividend->toArray(), ['cancellation_reason' => $reason])
            );
            
            DB::commit();
            
            return $dividend->fresh();
            
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
