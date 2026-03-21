<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFeatureFlagController extends Controller
{
    use HandlesApiErrors;

    private const GROUP = 'feature_flags';

    private const TYPE = 'feature_flag';

    public function index(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $flags = DB::table('frontend_settings')
                ->where('group', self::GROUP)
                ->orderBy('key')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $flags->map(fn ($flag) => $this->serializeFlag($flag))->values(),
            ]);
        }, 'Failed to retrieve feature flags.');
    }

    public function store(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $this->validatePayload($request, false);

            $id = DB::table('frontend_settings')->insertGetId([
                'key' => $validated['key'],
                'value' => json_encode($this->buildValuePayload($validated), JSON_THROW_ON_ERROR),
                'type' => self::TYPE,
                'group' => self::GROUP,
                'description' => $validated['description'] ?? null,
                'is_public' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $flag = DB::table('frontend_settings')->where('id', $id)->first();

            return response()->json([
                'success' => true,
                'message' => 'Feature flag created.',
                'data' => $this->serializeFlag($flag),
            ], 201);
        }, 'Failed to create feature flag.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $existing = DB::table('frontend_settings')
                ->where('group', self::GROUP)
                ->where('id', $id)
                ->first();

            abort_unless($existing, 404);

            $validated = $this->validatePayload($request, true);
            $currentValue = $this->decodeValue($existing->value);
            $nextValue = [
                ...$currentValue,
                ...$this->buildValuePayload($validated),
            ];

            $nextKey = $validated['key'] ?? $existing->key;
            $nextDescription = array_key_exists('description', $validated)
                ? $validated['description']
                : $existing->description;

            DB::table('frontend_settings')
                ->where('id', $id)
                ->update([
                    'key' => $nextKey,
                    'value' => json_encode($nextValue, JSON_THROW_ON_ERROR),
                    'description' => $nextDescription,
                    'updated_at' => now(),
                ]);

            $flag = DB::table('frontend_settings')->where('id', $id)->first();

            return response()->json([
                'success' => true,
                'message' => 'Feature flag updated.',
                'data' => $this->serializeFlag($flag),
            ]);
        }, 'Failed to update feature flag.');
    }

    public function destroy(int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            DB::table('frontend_settings')
                ->where('group', self::GROUP)
                ->where('id', $id)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Feature flag deleted.',
            ]);
        }, 'Failed to delete feature flag.');
    }

    private function validatePayload(Request $request, bool $partial): array
    {
        $keyRule = $partial
            ? 'sometimes|string|max:100'
            : 'required|string|max:100|unique:frontend_settings,key';

        return $request->validate([
            'key' => $keyRule,
            'name' => $partial ? 'sometimes|string|max:255' : 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'enabled' => 'sometimes|boolean',
            'rollout_percentage' => 'sometimes|integer|min:0|max:100',
            'environments' => 'sometimes|array|min:1',
            'environments.*' => 'string|in:production,staging,development',
            'conditions' => 'sometimes|array',
            'conditions.user_roles' => 'sometimes|array',
            'conditions.user_roles.*' => 'string',
            'conditions.user_ids' => 'sometimes|array',
            'conditions.user_ids.*' => 'integer',
            'conditions.regions' => 'sometimes|array',
            'conditions.regions.*' => 'string',
        ]);
    }

    private function buildValuePayload(array $validated): array
    {
        $payload = [];

        foreach (['name', 'description', 'enabled', 'rollout_percentage', 'environments', 'conditions'] as $key) {
            if (array_key_exists($key, $validated)) {
                $payload[$key] = $validated[$key];
            }
        }

        return $payload;
    }

    private function decodeValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function serializeFlag(object $flag): array
    {
        $value = $this->decodeValue($flag->value);

        return [
            'id' => (int) $flag->id,
            'key' => $flag->key,
            'name' => (string) ($value['name'] ?? $flag->key),
            'description' => (string) ($value['description'] ?? $flag->description ?? ''),
            'enabled' => (bool) ($value['enabled'] ?? false),
            'rollout_percentage' => (int) ($value['rollout_percentage'] ?? 100),
            'environments' => array_values($value['environments'] ?? ['production']),
            'conditions' => $value['conditions'] ?? [],
            'created_at' => $flag->created_at,
            'updated_at' => $flag->updated_at,
        ];
    }
}
