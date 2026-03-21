<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminAuditLogController extends Controller
{
    use HandlesApiErrors;

    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
            $search = trim((string) $request->query('search', ''));
            $action = trim((string) $request->query('action', ''));
            $resourceType = trim((string) $request->query('resource_type', ''));

            $logs = AuditLog::query()
                ->with('user:id,name,username,email')
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($subQuery) use ($search) {
                        $subQuery
                            ->where('action', 'like', "%{$search}%")
                            ->orWhere('auditable_type', 'like', "%{$search}%")
                            ->orWhere('url', 'like', "%{$search}%")
                            ->orWhereHas('user', function ($userQuery) use ($search) {
                                $userQuery
                                    ->where('name', 'like', "%{$search}%")
                                    ->orWhere('username', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                            });
                    });
                })
                ->when($action !== '', fn ($query) => $query->where('action', 'like', "%{$action}%"))
                ->when($resourceType !== '', function ($query) use ($resourceType) {
                    $query->where('auditable_type', 'like', '%'.$resourceType.'%');
                })
                ->latest()
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $logs->getCollection()->map(fn (AuditLog $log) => $this->serializeLog($log))->values(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'total' => $logs->total(),
                    'per_page' => $logs->perPage(),
                ],
            ]);
        }, 'Failed to retrieve audit logs.');
    }

    private function serializeLog(AuditLog $log): array
    {
        $resourceType = $this->resourceType($log->auditable_type);

        return [
            'id' => $log->id,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name ?: $log->user->username ?: $log->user->email,
                'email' => $log->user->email,
            ] : null,
            'action' => $log->action,
            'resource_type' => $resourceType,
            'resource_id' => $log->auditable_id,
            'description' => $this->buildDescription($log, $resourceType),
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'changes' => $this->mergeChanges($log->old_values ?? [], $log->new_values ?? []),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    private function resourceType(?string $auditableType): string
    {
        if (! $auditableType) {
            return 'system';
        }

        return Str::snake(class_basename($auditableType));
    }

    private function buildDescription(AuditLog $log, string $resourceType): string
    {
        $actor = $log->user?->name ?: $log->user?->username ?: $log->user?->email ?: 'System';
        $action = str_replace('_', ' ', Str::lower($log->action));

        if ($log->auditable_id) {
            return sprintf('%s %s on %s #%s', $actor, $action, $resourceType, $log->auditable_id);
        }

        return sprintf('%s %s', $actor, $action);
    }

    private function mergeChanges(array $oldValues, array $newValues): array
    {
        $keys = collect(array_keys($oldValues))
            ->merge(array_keys($newValues))
            ->unique()
            ->values();

        return $keys->mapWithKeys(fn (string $key) => [
            $key => [
                'old' => $oldValues[$key] ?? null,
                'new' => $newValues[$key] ?? null,
            ],
        ])->all();
    }
}
