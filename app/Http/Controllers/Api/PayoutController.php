<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayoutController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService,
    ) {}

    /**
     * POST /api/payouts/request — request a payout (artist)
     */
    public function requestPayout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'nullable|string|in:mobile_money,bank_transfer',
            'phone_number' => 'required_if:payment_method,mobile_money|nullable|string',
        ]);

        $user = $request->user();

        // Find the artist profile for this user
        $artist = \App\Models\Artist::where('user_id', $user->id)->firstOrFail();

        // Check if artist has enough revenue (based on total_revenue)
        $pendingPayouts = DB::table('payouts')
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->sum('amount');

        $availableBalance = ($artist->total_revenue ?? 0) - $pendingPayouts;

        if ($validated['amount'] > $availableBalance) {
            return response()->json([
                'message' => 'Insufficient balance for this payout amount.',
                'data' => ['available_balance' => $availableBalance],
            ], 422);
        }

        $payout = DB::table('payouts')->insertGetId([
            'user_id' => $user->id,
            'amount' => $validated['amount'],
            'currency' => 'UGX',
            'payment_method' => $validated['payment_method'] ?? 'mobile_money',
            'payment_details' => json_encode([
                'phone_number' => $validated['phone_number'] ?? null,
                'artist_id' => $artist->id,
            ]),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $record = DB::table('payouts')->where('id', $payout)->first();

        return response()->json([
            'data' => $record,
            'message' => 'Payout request submitted successfully.',
        ], 201);
    }
}
