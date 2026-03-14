<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceTokenController extends Controller
{
    /**
     * GET /api/device-tokens — list user's registered device tokens
     */
    public function index(Request $request): JsonResponse
    {
        $tokens = DeviceToken::where('user_id', $request->user()->id)
            ->active()
            ->orderByDesc('last_used_at')
            ->get(['id', 'platform', 'device_type', 'device_name', 'app_version', 'is_active', 'last_used_at', 'created_at']);

        return response()->json([
            'data' => $tokens,
        ]);
    }

    /**
     * POST /api/device-tokens — register a device token for push notifications
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'platform' => ['required', Rule::in(['ios', 'android', 'web'])],
            'device_info' => ['sometimes', 'array'],
            'device_info.name' => ['sometimes', 'string', 'max:100'],
            'device_info.model' => ['sometimes', 'string', 'max:100'],
            'device_info.os_version' => ['sometimes', 'string', 'max:50'],
            'device_info.app_version' => ['sometimes', 'string', 'max:50'],
        ]);

        $deviceToken = DeviceToken::registerToken(
            userId: $request->user()->id,
            token: $validated['token'],
            platform: $validated['platform'],
            deviceInfo: $validated['device_info'] ?? []
        );

        return response()->json([
            'data' => $deviceToken,
            'message' => 'Device token registered successfully.',
        ], 201);
    }

    /**
     * DELETE /api/device-tokens/{id} — remove a device token
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $token = DeviceToken::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $token->delete();

        return response()->json([
            'message' => 'Device token removed.',
        ]);
    }

    /**
     * POST /api/device-tokens/deactivate-all — deactivate all user's tokens (e.g., on logout)
     */
    public function deactivateAll(Request $request): JsonResponse
    {
        $count = DeviceToken::deactivateUserTokens($request->user()->id);

        return response()->json([
            'message' => "Deactivated {$count} device token(s).",
            'deactivated_count' => $count,
        ]);
    }
}
