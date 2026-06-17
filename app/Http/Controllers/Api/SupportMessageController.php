<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class SupportMessageController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * POST /api/support/messages
     *
     * A deliberately lightweight "contact the team" channel: rather than a full
     * conversation thread, the message is fanned out to every active admin and
     * moderator as an in-app notification so it surfaces in their existing
     * notification feed.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:5', 'max:2000'],
            'category' => ['nullable', 'string', 'in:general,bug,billing,abuse,artist,other'],
        ]);

        $sender = Auth::user();
        $category = $validated['category'] ?? 'general';
        $subject = trim((string) ($validated['subject'] ?? '')) ?: 'Support request';

        $recipients = $this->staffRecipients($sender->id);

        foreach ($recipients as $recipient) {
            Notification::createRichForUser(
                user: $recipient,
                type: 'support_message',
                title: "Support: {$subject}",
                message: $validated['message'],
                data: [
                    'module' => 'support',
                    'category' => $category,
                    'sender_id' => $sender->id,
                    'sender_name' => $sender->name,
                    'sender_email' => $sender->email,
                ],
                actionUrl: "/admin/users/{$sender->id}",
                category: 'support',
                actorId: $sender->id,
                priority: $category === 'abuse' ? 'high' : 'normal',
            );
        }

        return response()->json([
            'message' => 'Message sent. Our team will get back to you by email.',
            'data' => ['recipient_count' => $recipients->count()],
        ], 201);
    }

    /**
     * Active admins and moderators (excluding the sender), resolved from both the
     * user_roles pivot and the legacy direct `role` column for compatibility.
     */
    private function staffRecipients(int $excludeUserId): Collection
    {
        $roleNames = ['admin', 'Admin', 'super_admin', 'Super Admin', 'moderator', 'Moderator'];
        $hasRoleColumn = Schema::hasColumn('users', 'role');

        return User::query()
            ->where('is_active', true)
            ->where('id', '!=', $excludeUserId)
            ->where(function ($query) use ($roleNames, $hasRoleColumn) {
                $query->whereHas('activeRoles', function ($roleQuery) use ($roleNames) {
                    $roleQuery->whereIn('name', $roleNames);
                });

                if ($hasRoleColumn) {
                    $query->orWhereIn('role', ['admin', 'super_admin', 'moderator']);
                }
            })
            ->get();
    }
}
