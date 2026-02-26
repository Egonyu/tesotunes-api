<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSubscription;
use App\Notifications\SubscriptionNotification;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService,
    ) {}

    /**
     * POST /api/subscriptions/{subscription}/cancel — cancel subscription
     */
    public function cancel(Request $request, $subscription): JsonResponse
    {
        $subscription = UserSubscription::where('id', $subscription)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (in_array($subscription->status, ['cancelled', 'expired'])) {
            return response()->json([
                'message' => 'Subscription is already '.$subscription->status.'.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $result = $this->paymentService->cancelSubscription(
            $subscription,
            $validated['reason'] ?? ''
        );

        // Notify user about subscription cancellation
        $request->user()->notify(new SubscriptionNotification(
            SubscriptionNotification::CANCELLED,
            $subscription->plan->name ?? 'Premium',
            ['expires_at' => $subscription->ends_at?->format('M d, Y') ?? 'N/A']
        ));

        return response()->json([
            'data' => $result,
            'message' => 'Subscription cancelled successfully.',
        ]);
    }

    /**
     * POST /api/subscriptions/{subscription}/extend — extend subscription (admin)
     */
    public function extend(Request $request, $subscription): JsonResponse
    {
        $subscription = UserSubscription::findOrFail($subscription);

        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:365',
            'reason' => 'nullable|string|max:500',
        ]);

        $result = $this->paymentService->extendSubscription(
            $subscription,
            $validated['days'],
            $validated['reason'] ?? ''
        );

        return response()->json([
            'data' => $result,
            'message' => 'Subscription extended successfully.',
        ]);
    }
}
