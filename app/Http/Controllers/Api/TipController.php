<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\ArtistRevenue;
use App\Models\Payment;
use App\Models\Song;
use App\Services\Settings\ArtistSettingsService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TipController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'recipient_id' => ['required', 'integer', 'min:1'],
            'recipient_type' => ['required', 'string', 'in:artist,song'],
            'amount' => ['required', 'integer', 'min:1'],
            'message' => ['nullable', 'string', 'max:200'],
        ]);

        [$recipientUser, $payableType, $payableId, $songId, $artistId] = $this->resolveRecipient(
            (string) $validated['recipient_type'],
            (int) $validated['recipient_id']
        );

        if (! $recipientUser) {
            return response()->json([
                'success' => false,
                'message' => 'Tip recipient could not be found.',
            ], 404);
        }

        if ($recipientUser->id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot tip your own account.',
            ], 422);
        }

        $tipAmount = (int) $validated['amount'];
        $artistSharePct = max(0, min(100, (float) app(ArtistSettingsService::class)->getRevenueShare()));
        $artistNetAmount = (int) round($tipAmount * ($artistSharePct / 100));
        $platformFeeAmount = $tipAmount - $artistNetAmount;

        $payment = DB::transaction(function () use ($user, $validated, $recipientUser, $payableType, $payableId, $songId, $artistId, $tipAmount, $artistNetAmount, $platformFeeAmount) {
            $transaction = $user->spendCredits(
                (float) $tipAmount,
                'artist_tip',
                "Sent a tip to {$recipientUser->name}",
                [
                    'recipient_user_id' => $recipientUser->id,
                    'recipient_type' => $validated['recipient_type'],
                    'recipient_id' => (int) $validated['recipient_id'],
                ]
            );

            if (! $transaction) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Insufficient credits.',
                ], 422));
            }

            // Credit the artist's wallet with their net share
            $recipientUser->creditWallet?->addCredits(
                (float) $artistNetAmount,
                'tip_received',
                'Tip received',
                [
                    'payer_user_id' => $user->id,
                    'message' => $validated['message'] ?? null,
                ]
            );

            // Record artist revenue
            if ($artistId) {
                ArtistRevenue::create([
                    'artist_id' => $artistId,
                    'revenue_type' => ArtistRevenue::TYPE_TIP,
                    'sourceable_type' => $payableType,
                    'sourceable_id' => $payableId,
                    'amount_ugx' => $tipAmount,
                    'platform_fee' => $platformFeeAmount,
                    'net_amount' => $artistNetAmount,
                    'status' => ArtistRevenue::STATUS_CONFIRMED,
                    'revenue_date' => now()->toDateString(),
                ]);
            }

            $payment = new Payment([
                'user_id' => $user->id,
                'payable_type' => $payableType,
                'payable_id' => $payableId,
                'song_id' => $songId,
                'payment_type' => 'tip',
                'payment_method' => 'credits',
                'provider' => 'internal_credits',
                'payment_reference' => 'TIP-'.Str::upper(Str::random(12)),
                'transaction_reference' => 'TIP-'.Str::upper(Str::random(12)),
                'currency' => 'credits',
                'description' => "Tip for {$recipientUser->name}",
                'metadata' => [
                    'recipient_user_id' => $recipientUser->id,
                    'recipient_type' => $validated['recipient_type'],
                    'recipient_id' => (int) $validated['recipient_id'],
                    'message' => $validated['message'] ?? null,
                    'credits_amount' => $tipAmount,
                    'artist_net_amount' => $artistNetAmount,
                    'platform_fee' => $platformFeeAmount,
                ],
            ]);

            $payment->forceFill([
                'amount' => $tipAmount,
                'status' => Payment::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();

            return $payment;
        });

        return response()->json([
            'success' => true,
            'message' => 'Tip sent successfully.',
            'data' => [
                'tip_id' => $payment->id,
                'amount' => $tipAmount,
                'credits_remaining' => (int) $user->fresh()->credits,
            ],
        ], 201);
    }

    private function resolveRecipient(string $recipientType, int $recipientId): array
    {
        if ($recipientType === 'song') {
            $song = Song::with('artist.user')->find($recipientId);
            $recipientUser = $song?->artist?->user;

            return [
                $recipientUser,
                Song::class,
                $song?->id,
                $song?->id,
                $song?->artist_id,
            ];
        }

        $artist = Artist::with('user')->find($recipientId);

        return [
            $artist?->user,
            Artist::class,
            $artist?->id,
            null,
            $artist?->id,
        ];
    }
}
