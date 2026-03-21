<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\EventStaffMember;
use Closure;
use Illuminate\Http\Request;

class EventOpsRoleMiddleware
{
    /**
     * Allow event operations for organizers, platform admins, and event staff
     * who have an operations-capable role on the target event.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->hasAnyRole(['admin', 'super_admin'])) {
            return $next($request);
        }

        $eventId = (int) $request->route('id');
        $event = Event::with('staffMembers')->findOrFail($eventId);

        $isOwner = Event::query()
            ->whereKey($event->id)
            ->ownedByUser($user)
            ->exists();

        if ($isOwner) {
            return $next($request);
        }

        $hasOpsMembership = $event->staffMembers->contains(
            fn (EventStaffMember $member) => $member->user_id === $user->id
                && in_array($member->role, [
                    EventStaffMember::ROLE_FINANCE,
                    EventStaffMember::ROLE_CHECK_IN,
                    EventStaffMember::ROLE_ANALYST,
                ], true)
        );

        abort_unless($hasOpsMembership, 403, 'You do not have access to this event operation.');

        return $next($request);
    }
}
