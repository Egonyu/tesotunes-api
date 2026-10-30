<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Admin\UserAudit\UserAuditService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin "User 360" audit view: who a user is, what they earned and withdrew, how they
 * behave, and which advisory flags apply. Admin and super admin only (never moderators):
 * the response carries payout numbers and activity history.
 */
class AdminUserAuditController extends Controller
{
    use HandlesApiErrors;

    public function __construct(private readonly UserAuditService $audit) {}

    public function show(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $user = User::findOrFail($id);

            if ($denied = $this->denyUnlessManageable($request, $user)) {
                return $denied;
            }

            $request->validate([
                'sections' => ['nullable', 'string'],
                'reveal' => ['nullable', 'boolean'],
            ]);

            $reveal = $request->boolean('reveal');
            $sections = $request->filled('sections') ? array_map('trim', explode(',', $request->string('sections'))) : null;

            $this->recordAccess($request, $user, $reveal ? 'admin_user_audit_pii_revealed' : 'admin_user_audit_viewed');

            return response()->json(['success' => true, 'data' => $this->audit->build($user, $reveal, $sections)]);
        }, 'Failed to load user audit.');
    }

    public function timeline(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $user = User::findOrFail($id);

            if ($denied = $this->denyUnlessManageable($request, $user)) {
                return $denied;
            }

            $filters = $request->validate([
                'kinds' => ['nullable', 'array'],
                'kinds.*' => ['in:audit,security,kyc,credits'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
                'include_noise' => ['nullable', 'boolean'],
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1'],
            ]);

            return response()->json(['success' => true, ...$this->audit->timeline($user, $filters)]);
        }, 'Failed to load user timeline.');
    }

    private function denyUnlessManageable(Request $request, User $user): ?JsonResponse
    {
        if ($request->user()->canManageUser($user)) {
            return null;
        }

        return response()->json(['success' => false, 'message' => 'Insufficient permissions to audit this user.'], 403);
    }

    /** Every look at a user's audit view is itself audited, including who revealed personal data. */
    private function recordAccess(Request $request, User $target, string $action): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'new_values' => ['target_user_id' => $target->id],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'request_id' => $request->attributes->get('observability_request_id'),
            'trace_id' => $request->attributes->get('observability_trace_id'),
            'session_id' => $request->attributes->get('observability_session_id'),
        ]);
    }
}
