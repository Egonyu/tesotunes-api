<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogSubmission;
use App\Services\CatalogIntakeService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogSubmissionController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly CatalogIntakeService $catalogIntakeService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $user = $request->user();
            $this->ensureCanViewSubmissions($user);

            $query = CatalogSubmission::query()
                ->with(['uploader', 'items.artist', 'items.song'])
                ->latest();

            if (! $user->hasPermission('catalog.view') && ! $user->hasAnyRole(['admin', 'super_admin'])) {
                $query->where('uploader_user_id', $user->id);
            }

            return response()->json([
                'data' => $query->paginate(min((int) $request->integer('per_page', 20), 100)),
            ]);
        }, 'Failed to fetch catalog submissions.');
    }

    public function show(Request $request, CatalogSubmission $submission): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $submission) {
            $user = $request->user();
            $this->ensureCanViewSubmissions($user);

            if (
                $submission->uploader_user_id !== $user->id
                && ! $user->hasPermission('catalog.view')
                && ! $user->hasAnyRole(['admin', 'super_admin'])
            ) {
                abort(403, 'You do not have permission to view this submission.');
            }

            return response()->json([
                'data' => $submission->load(['uploader', 'items.artist', 'items.song']),
            ]);
        }, 'Failed to fetch catalog submission.');
    }

    public function store(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $user = $request->user();
            $this->ensureCanUpload($user);

            $validated = $request->validate([
                'csv_file' => 'required|file|mimes:csv,txt',
                'audio_files' => 'required|array|min:1',
                'audio_files.*' => 'file|mimes:mp3,wav,flac,aac,m4a,ogg|max:102400',
                'cover_files' => 'nullable|array',
                'cover_files.*' => 'file|image|mimes:jpeg,jpg,png,webp|max:10240',
                'source_name' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:2000',
            ]);

            $submission = $this->catalogIntakeService->createSubmission(
                uploader: $user,
                csvFile: $request->file('csv_file'),
                audioFiles: $request->file('audio_files', []),
                coverFiles: $request->file('cover_files', []),
                sourceName: $validated['source_name'] ?? null,
                notes: $validated['notes'] ?? null,
            );

            return response()->json([
                'message' => 'Catalog submission processed successfully.',
                'data' => $submission,
            ], 201);
        }, 'Failed to create catalog submission.');
    }

    private function ensureCanUpload($user): void
    {
        if ($user->hasAnyRole(['admin', 'super_admin']) || $user->hasPermission('catalog.upload')) {
            return;
        }

        abort(403, 'You do not have permission to upload catalog submissions.');
    }

    private function ensureCanViewSubmissions($user): void
    {
        if (
            $user->hasAnyRole(['admin', 'super_admin'])
            || $user->hasPermission('catalog.view')
            || $user->hasPermission('catalog.manage_own')
        ) {
            return;
        }

        abort(403, 'You do not have permission to view catalog submissions.');
    }
}
