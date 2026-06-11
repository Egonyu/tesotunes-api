<?php

namespace App\Http\Middleware;

use App\Models\Event;
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

        $eventId = (int) $request->route('id');
        $event = Event::findOrFail($eventId);

        abort_unless($event->canBeOperatedBy($user), 403, 'You do not have access to this event operation.');

        return $next($request);
    }
}
