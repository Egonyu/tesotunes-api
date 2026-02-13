<?php

namespace App\Http\Controllers\Api\Sacco;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaccoShareResource;
use App\Models\Sacco\SaccoMember;
use App\Models\Sacco\SaccoShare;
use App\Models\Sacco\SaccoShareTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaccoSharesController extends Controller
{
    /**
     * POST /api/sacco/shares/purchase — purchase shares
     */
    public function purchase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => 'required|integer|exists:sacco_members,id',
            'shares_quantity' => 'required|integer|min:1',
        ]);

        $member = SaccoMember::where('status', 'active')->findOrFail($validated['member_id']);
        $pricePerShare = config('sacco.share_price', 50000);

        $totalAmount = $validated['shares_quantity'] * $pricePerShare;

        DB::transaction(function () use ($member, $validated, $pricePerShare, $totalAmount) {
            $share = SaccoShare::firstOrCreate(
                ['member_id' => $member->id],
                ['total_shares' => 0, 'share_value_ugx' => $pricePerShare, 'total_value_ugx' => 0]
            );

            $share->increment('total_shares', $validated['shares_quantity']);
            $share->increment('total_value_ugx', $totalAmount);
            $share->update([
                'share_value_ugx' => $pricePerShare,
                'last_purchase_at' => now(),
            ]);

            SaccoShareTransaction::create([
                'member_id' => $member->id,
                'share_id' => $share->id,
                'type' => 'purchase',
                'shares_quantity' => $validated['shares_quantity'],
                'price_per_share_ugx' => $pricePerShare,
                'total_amount_ugx' => $totalAmount,
                'status' => 'completed',
            ]);
        });

        $share = SaccoShare::where('member_id', $member->id)->first();

        return response()->json([
            'data' => new SaccoShareResource($share),
            'message' => "{$validated['shares_quantity']} shares purchased successfully.",
        ], 201);
    }

    /**
     * POST /api/sacco/shares/transfer — transfer shares between members
     */
    public function transfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_member_id' => 'required|integer|exists:sacco_members,id',
            'to_member_id' => 'required|integer|exists:sacco_members,id|different:from_member_id',
            'shares_quantity' => 'required|integer|min:1',
        ]);

        $fromShare = SaccoShare::where('member_id', $validated['from_member_id'])->firstOrFail();

        if ($fromShare->total_shares < $validated['shares_quantity']) {
            return response()->json([
                'message' => 'Insufficient shares for transfer.',
                'data' => ['available_shares' => $fromShare->total_shares],
            ], 422);
        }

        $pricePerShare = $fromShare->share_value_ugx;
        $totalAmount = $validated['shares_quantity'] * $pricePerShare;

        DB::transaction(function () use ($fromShare, $validated, $pricePerShare, $totalAmount) {
            // Deduct from sender
            $fromShare->decrement('total_shares', $validated['shares_quantity']);
            $fromShare->decrement('total_value_ugx', $totalAmount);

            SaccoShareTransaction::create([
                'member_id' => $validated['from_member_id'],
                'share_id' => $fromShare->id,
                'type' => 'transfer_out',
                'shares_quantity' => $validated['shares_quantity'],
                'price_per_share_ugx' => $pricePerShare,
                'total_amount_ugx' => $totalAmount,
                'status' => 'completed',
                'notes' => "Transfer to member #{$validated['to_member_id']}",
            ]);

            // Add to receiver
            $toShare = SaccoShare::firstOrCreate(
                ['member_id' => $validated['to_member_id']],
                ['total_shares' => 0, 'share_value_ugx' => $pricePerShare, 'total_value_ugx' => 0]
            );

            $toShare->increment('total_shares', $validated['shares_quantity']);
            $toShare->increment('total_value_ugx', $totalAmount);

            SaccoShareTransaction::create([
                'member_id' => $validated['to_member_id'],
                'share_id' => $toShare->id,
                'type' => 'transfer_in',
                'shares_quantity' => $validated['shares_quantity'],
                'price_per_share_ugx' => $pricePerShare,
                'total_amount_ugx' => $totalAmount,
                'status' => 'completed',
                'notes' => "Transfer from member #{$validated['from_member_id']}",
            ]);
        });

        return response()->json([
            'message' => "Successfully transferred {$validated['shares_quantity']} shares.",
        ]);
    }

    /**
     * GET /api/sacco/shares/member/{member} — member's shares
     */
    public function memberShares($member)
    {
        $share = SaccoShare::with('transactions')
            ->where('member_id', $member)
            ->first();

        if (!$share) {
            return response()->json(['data' => null, 'message' => 'No shares found for this member.']);
        }

        return new SaccoShareResource($share);
    }

    /**
     * GET /api/sacco/shares/value — current share value
     */
    public function currentValue(): JsonResponse
    {
        $pricePerShare = config('sacco.share_price', 50000);
        $totalSharesIssued = SaccoShare::sum('total_shares');
        $totalValue = SaccoShare::sum('total_value_ugx');

        return response()->json([
            'data' => [
                'price_per_share_ugx' => $pricePerShare,
                'total_shares_issued' => (int) $totalSharesIssued,
                'total_market_value_ugx' => $totalValue,
            ],
        ]);
    }
}
