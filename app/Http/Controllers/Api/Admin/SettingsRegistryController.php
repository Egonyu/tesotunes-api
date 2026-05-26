<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SettingAudit;
use App\Settings\Enums\SettingVisibility;
use App\Settings\SettingDefinition;
use App\Settings\SettingsManager;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SettingsRegistryController extends Controller
{
    use HandlesApiErrors;

    private const ROLE_RANK = [
        'public' => 0,
        'authenticated' => 1,
        'user' => 1,
        'fan' => 1,
        'moderator' => 2,
        'admin' => 3,
        'super_admin' => 4,
    ];

    public function __construct(private readonly SettingsManager $manager) {}

    public function schema(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $role = $this->callerRole($request);

            $defs = array_filter(
                $this->manager->registry()->all(),
                fn (SettingDefinition $d) => $this->canView($role, $d),
            );

            return response()->json([
                'success' => true,
                'data' => array_map(fn (SettingDefinition $d) => $this->serializeDefinition($d), array_values($defs)),
            ]);
        }, 'Failed to retrieve settings schema.');
    }

    public function values(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $role = $this->callerRole($request);

            $defs = array_filter(
                $this->manager->registry()->all(),
                fn (SettingDefinition $d) => $this->canView($role, $d),
            );

            $keys = array_map(fn ($d) => $d->key, $defs);

            // Audit log is the single source of truth for metadata across all stores
            // (DB, encrypted DB, SACCO table). The settings table cannot serve SACCO keys.
            $latestAudits = SettingAudit::query()
                ->whereIn('setting_key', $keys)
                ->orderByDesc('id')
                ->get()
                ->unique('setting_key')
                ->keyBy('setting_key');

            $data = [];
            foreach ($defs as $def) {
                $audit = $latestAudits->get($def->key);
                $data[] = [
                    'key' => $def->key,
                    'value' => $def->secret ? null : $this->manager->get($def->key),
                    'configured' => $audit !== null,
                    'version' => (int) ($audit?->new_version ?? 0),
                    'last_updated_by' => $audit?->actor_user_id,
                    'updated_at' => $audit?->changed_at?->toIso8601String(),
                ];
            }

            return response()->json(['success' => true, 'data' => $data]);
        }, 'Failed to retrieve settings values.');
    }

    public function patchOne(Request $request, string $key): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $key) {
            $definition = $this->manager->registry()->get($key);
            if ($definition === null) {
                return response()->json(['success' => false, 'message' => 'Unknown setting.'], 404);
            }

            $role = $this->callerRole($request);
            if (! $this->canUpdate($role, $definition)) {
                return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
            }

            $payload = $request->validate([
                'value' => array_merge(['present'], $definition->rules),
                'expected_version' => 'sometimes|integer|min:0',
                'reason' => 'sometimes|string|max:255',
            ]);

            $currentVersion = $this->currentVersion($key);
            if (array_key_exists('expected_version', $payload) && $payload['expected_version'] !== $currentVersion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Version mismatch.',
                    'current_version' => $currentVersion,
                ], 409);
            }

            Setting::withActor(
                $request->user()?->id,
                fn () => $this->manager->set($key, $payload['value']),
                $payload['reason'] ?? null,
            );

            $newVersion = $this->currentVersion($key);

            return response()->json([
                'success' => true,
                'data' => [
                    'key' => $key,
                    'value' => $definition->secret ? null : $this->manager->get($key),
                    'configured' => $newVersion > 0,
                    'version' => $newVersion,
                ],
            ]);
        }, 'Failed to update setting.');
    }

    public function patchBatch(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'updates' => 'required|array|min:1',
                'reason' => 'sometimes|string|max:255',
            ]);

            $role = $this->callerRole($request);
            $reason = $validated['reason'] ?? null;
            $results = [];
            $hasFailure = false;

            DB::beginTransaction();
            try {
                foreach ($validated['updates'] as $key => $patch) {
                    $definition = $this->manager->registry()->get($key);
                    if ($definition === null) {
                        $results[$key] = ['status' => 404, 'message' => 'Unknown setting.'];
                        $hasFailure = true;

                        continue;
                    }
                    if (! $this->canUpdate($role, $definition)) {
                        $results[$key] = ['status' => 403, 'message' => 'Forbidden.'];
                        $hasFailure = true;

                        continue;
                    }
                    if (! is_array($patch) || ! array_key_exists('value', $patch)) {
                        $results[$key] = ['status' => 422, 'message' => 'Missing value.'];
                        $hasFailure = true;

                        continue;
                    }

                    $current = $this->currentVersion($key);
                    if (isset($patch['expected_version']) && (int) $patch['expected_version'] !== $current) {
                        $results[$key] = ['status' => 409, 'message' => 'Version mismatch.', 'current_version' => $current];
                        $hasFailure = true;

                        continue;
                    }

                    $validator = Validator::make(['value' => $patch['value']], ['value' => array_merge(['present'], $definition->rules)]);
                    if ($validator->fails()) {
                        $results[$key] = ['status' => 422, 'errors' => $validator->errors()->toArray()];
                        $hasFailure = true;

                        continue;
                    }

                    Setting::withActor(
                        $request->user()?->id,
                        fn () => $this->manager->set($key, $patch['value']),
                        $reason,
                    );

                    $results[$key] = [
                        'status' => 200,
                        'version' => $this->currentVersion($key),
                    ];
                }

                if ($hasFailure) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'One or more updates failed; no changes were saved.',
                        'data' => $results,
                    ], 422);
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            return response()->json(['success' => true, 'data' => $results]);
        }, 'Failed to apply batch update.');
    }

    public function history(Request $request, string $key): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $key) {
            $definition = $this->manager->registry()->get($key);
            if ($definition === null) {
                return response()->json(['success' => false, 'message' => 'Unknown setting.'], 404);
            }

            $role = $this->callerRole($request);
            if (! $this->canView($role, $definition)) {
                return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
            }

            $perPage = min(100, max(1, (int) $request->input('per_page', 25)));

            $page = SettingAudit::query()
                ->where('setting_key', $key)
                ->with('actor:id,name,email')
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $page->items(),
                'meta' => [
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                ],
            ]);
        }, 'Failed to retrieve setting history.');
    }

    public function revert(Request $request, string $key, int $auditId): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $key, $auditId) {
            $definition = $this->manager->registry()->get($key);
            if ($definition === null) {
                return response()->json(['success' => false, 'message' => 'Unknown setting.'], 404);
            }

            $role = $this->callerRole($request);
            if (! $this->canUpdate($role, $definition)) {
                return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
            }

            $audit = SettingAudit::query()
                ->where('id', $auditId)
                ->where('setting_key', $key)
                ->first();

            if ($audit === null) {
                return response()->json(['success' => false, 'message' => 'Audit row not found.'], 404);
            }

            if ($audit->was_secret) {
                return response()->json([
                    'success' => false,
                    'message' => 'Secret values cannot be reverted (no plaintext stored).',
                ], 409);
            }

            if ($audit->old_value === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'No prior value to revert to (this was the first recorded change).',
                ], 409);
            }

            $restored = $definition->type->cast($audit->old_value);

            Setting::withActor(
                $request->user()?->id,
                fn () => $this->manager->set($key, $restored),
                "revert from audit #{$audit->id}",
            );

            SettingAudit::query()
                ->where('setting_key', $key)
                ->orderByDesc('id')
                ->limit(1)
                ->update(['reverted_from' => $audit->id]);

            return response()->json([
                'success' => true,
                'data' => [
                    'key' => $key,
                    'value' => $definition->secret ? null : $this->manager->get($key),
                    'reverted_from_audit_id' => $audit->id,
                ],
            ]);
        }, 'Failed to revert setting.');
    }

    public function publicIndex(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $public = $this->manager->registry()->publicKeys();
            $data = [];
            foreach ($public as $def) {
                if ($def->isDeprecated()) {
                    continue;
                }
                $data[] = [
                    'key' => $def->key,
                    'group' => $def->group,
                    'subgroup' => $def->subgroup,
                    'value' => $this->manager->get($def->key),
                ];
            }

            return response()->json(['success' => true, 'data' => $data]);
        }, 'Failed to retrieve public settings.');
    }

    private function callerRole(Request $request): string
    {
        return (string) ($request->user()?->role ?? 'public');
    }

    /**
     * Current version of a setting, derived from the audit log so it works
     * uniformly across the settings table and the sacco_settings table.
     */
    private function currentVersion(string $key): int
    {
        return (int) (SettingAudit::query()
            ->where('setting_key', $key)
            ->orderByDesc('id')
            ->value('new_version') ?? 0);
    }

    private function canView(string $role, SettingDefinition $definition): bool
    {
        $callerRank = self::ROLE_RANK[$role] ?? 0;
        $required = match ($definition->visibility) {
            SettingVisibility::Public => self::ROLE_RANK['public'],
            SettingVisibility::Authenticated => self::ROLE_RANK['authenticated'],
            SettingVisibility::Admin => self::ROLE_RANK['admin'],
            SettingVisibility::SuperAdmin => self::ROLE_RANK['super_admin'],
        };

        return $callerRank >= $required;
    }

    private function canUpdate(string $role, SettingDefinition $definition): bool
    {
        if (! in_array($role, $definition->editableBy, true)) {
            return false;
        }

        return $this->canView($role, $definition);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDefinition(SettingDefinition $def): array
    {
        return [
            'key' => $def->key,
            'group' => $def->group,
            'subgroup' => $def->subgroup,
            'type' => $def->type->value,
            'default' => $def->secret ? null : $def->default,
            'rules' => $def->rules,
            'options' => $def->options,
            'visibility' => $def->visibility->value,
            'editable_by' => $def->editableBy,
            'requires_restart' => $def->requiresRestart,
            'secret' => $def->secret,
            'label' => $def->label,
            'help' => $def->help,
            'audit_category' => $def->auditCategory,
            'deprecated_in_favor_of' => $def->deprecatedInFavorOf,
        ];
    }
}
