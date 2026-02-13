<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Services\CreditService;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class CreditController extends Controller
{
    protected CreditService $creditService;

    public function __construct(CreditService $creditService)
    {
        $this->middleware('auth:sanctum');
        $this->creditService = $creditService;
    }

    /**
     * Get user's credit dashboard data
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $summary = $this->creditService->getUserCreditSummary($user);
            $opportunities = $this->creditService->getPromotionOpportunities($user);

            return response()->json([
                'success' => true,
                'data' => [
                    'wallet' => $summary,
                    'earning_opportunities' => $this->getEarningOpportunities($user),
                    'promotion_opportunities' => $opportunities,
                    'daily_challenges' => $this->getDailyChallenges($user),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load credit dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get transaction history
     */
    public function transactions(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $perPage = min($request->get('per_page', 20), 50);

            $query = $user->creditTransactions()->latest('processed_at');

            // Apply filters
            if ($request->filled('type')) {
                $query->where('following_type', $request->type);
            }

            if ($request->filled('source')) {
                $query->where('source', $request->source);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('processed_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('processed_at', '<=', $request->date_to);
            }

            $transactions = $query->paginate($perPage);

            // Transform the data
            $transactions->getCollection()->transform(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => $transaction->formatted_amount,
                    'description' => $transaction->description,
                    'source' => $transaction->source_description,
                    'balance_after' => number_format($transaction->balance_after, 0) . ' credits',
                    'date' => $transaction->processed_at->format('M j, Y g:i A'),
                    'relative_date' => $transaction->processed_at->diffForHumans(),
                    'icon' => $transaction->type_icon,
                    'related_user' => $transaction->relatedUser ? [
                        'id' => $transaction->relatedUser->id,
                        'name' => $transaction->relatedUser->name,
                        'avatar_url' => $transaction->relatedUser->avatar_url,
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'transactions' => $transactions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load transactions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Claim daily login bonus
     */
    public function claimDailyBonus(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $transaction = $this->creditService->awardDailyLoginBonus($user);

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Daily bonus already claimed or not available'
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Daily bonus claimed successfully!',
                'transaction' => [
                    'amount' => $transaction->formatted_amount,
                    'description' => $transaction->description,
                    'new_balance' => number_format($transaction->balance_after, 0) . ' credits',
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to claim daily bonus',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Transfer credits to another user
     */
    public function transfer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recipient_id' => 'required|exists:users,id|different:' . $request->user()->id,
            'amount' => 'required|numeric|min:1|max:1000',
            'message' => 'nullable|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = $request->user();
            $recipient = \App\Models\User::findOrFail($request->recipient_id);

            $result = $this->creditService->transferCredits(
                $user,
                $recipient,
                $request->amount,
                $request->message ?: "Credit transfer to {$recipient->name}"
            );

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient credits for transfer'
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully transferred {$request->amount} credits to {$recipient->name}",
                'transaction' => [
                    'amount' => number_format($request->amount, 0) . ' credits',
                    'recipient' => $recipient->name,
                    'new_balance' => number_format($result['sender_transaction']->balance_after, 0) . ' credits',
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Transfer failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get community promotions
     */
    public function promotions(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $wallet = $this->creditService->getUserWallet($user);

            $promotions = Promotion::with(['platform', 'user'])
                ->active()
                ->available()
                ->orderByDesc('rating_average')
                ->limit(10)
                ->get()
                ->map(function ($promotion) use ($wallet) {
                    return [
                        'id' => $promotion->id,
                        'title' => $promotion->title,
                        'description' => $promotion->description,
                        'type' => $promotion->type,
                        'platform' => $promotion->platform?->name,
                        'cost' => $promotion->price_display,
                        'cost_credits' => $promotion->price_credits,
                        'cost_ugx' => $promotion->price_ugx,
                        'reach' => $promotion->formatted_reach,
                        'rating' => $promotion->rating_average,
                        'reviews' => $promotion->rating_count,
                        'delivery' => $promotion->delivery_display,
                        'can_afford' => $wallet->balance >= $promotion->price_credits,
                        'promoter' => [
                            'name' => $promotion->promoter_name ?? $promotion->user->name,
                            'avatar_url' => $promotion->promoter_avatar_url,
                            'verified' => $promotion->promoter_is_verified,
                        ],
                        'requirements' => $promotion->requirements,
                        'deliverables' => $promotion->deliverables,
                    ];
                });

            return response()->json([
                'success' => true,
                'promotions' => $promotions,
                'user_balance' => number_format($wallet->balance, 0) . ' credits'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load promotions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Participate in a community promotion
     */
    public function participateInPromotion(Request $request, Promotion $promotion): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'completion_data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = $request->user();

            // Check if promotion is active and available
            if ($promotion->status !== 'active' ||
                $promotion->starts_at > now() ||
                $promotion->ends_at < now() ||
                $promotion->current_participants >= $promotion->max_participants) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promotion is not available'
                ], 422);
            }

            // Check if user already participated
            if ($promotion->participants()->where('user_id', $user->id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already participated in this promotion'
                ], 422);
            }

            // Process payment
            $transaction = $this->creditService->spendCreditsForPromotion(
                $user,
                $promotion->credit_cost,
                $promotion->type,
                ['promotion_id' => $promotion->id]
            );

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient credits'
                ], 422);
            }

            // Create participation record
            $participation = $promotion->participants()->create([
                'user_id' => $user->id,
                'credits_spent' => $promotion->credit_cost,
                'completion_data' => $request->completion_data,
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            // Update promotion participant count
            $promotion->increment('current_participants');

            return response()->json([
                'success' => true,
                'message' => 'Successfully joined promotion!',
                'participation' => $participation,
                'new_balance' => number_format($transaction->balance_after, 0) . ' credits',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to join promotion',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Private helper methods
    private function getEarningOpportunities($user): array
    {
        $wallet = $this->creditService->getUserWallet($user);
        $remaining = $wallet->credits_earned_today;

        return [
            [
                'title' => 'Listen to Music',
                'description' => 'Earn credits by listening to songs',
                'potential_credits' => '0.5 - 1 credit per song',
                'daily_limit' => '50 credits',
                'remaining_today' => max(0, 50 - $remaining),
                'action' => 'Start listening',
                'icon' => '🎵',
            ],
            [
                'title' => 'Social Interactions',
                'description' => 'Like, share, and comment on music',
                'potential_credits' => '1 - 2 credits per action',
                'daily_limit' => '30 credits',
                'remaining_today' => max(0, 30 - $remaining),
                'action' => 'Be social',
                'icon' => '❤️',
            ],
            [
                'title' => 'Create Playlists',
                'description' => 'Create and share playlists',
                'potential_credits' => '5 credits per playlist',
                'daily_limit' => '25 credits',
                'remaining_today' => max(0, 25 - $remaining),
                'action' => 'Create playlist',
                'icon' => '📝',
            ],
            [
                'title' => 'Invite Friends',
                'description' => 'Refer new users to the platform',
                'potential_credits' => '50 credits per referral',
                'daily_limit' => '100 credits',
                'remaining_today' => max(0, 100 - $remaining),
                'action' => 'Share invite link',
                'icon' => '👥',
            ],
        ];
    }

    private function getDailyChallenges($user): array
    {
        return [
            [
                'title' => 'Music Explorer',
                'description' => 'Listen to 5 different artists today',
                'progress' => rand(1, 5),
                'target' => 5,
                'reward' => '10 bonus credits',
                'completed' => false,
            ],
            [
                'title' => 'Social Butterfly',
                'description' => 'Like and share 3 songs',
                'progress' => rand(0, 3),
                'target' => 3,
                'reward' => '8 bonus credits',
                'completed' => false,
            ],
            [
                'title' => 'Community Builder',
                'description' => 'Follow 2 new users',
                'progress' => rand(0, 2),
                'target' => 2,
                'reward' => '5 bonus credits',
                'completed' => false,
            ],
        ];
    }
}