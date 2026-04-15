<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\LegalPage;
use App\Models\LegalPageVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegalPagesController
{
    /**
     * GET /api/admin/legal-pages - List all legal pages with filtering
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = LegalPage::query();

            // Filter by type
            if ($request->get('type')) {
                $query->byType($request->get('type'));
            }

            // Filter by status
            if ($request->get('status')) {
                $query->where('status', $request->get('status'));
            }

            // Filter by applies_to
            if ($request->get('applies_to')) {
                $query->where('applies_to', $request->get('applies_to'));
            }

            // Search by title or slug
            if ($request->get('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            }

            $pages = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json([
                'success' => true,
                'data' => $pages->items(),
                'meta' => [
                    'total' => $pages->total(),
                    'per_page' => $pages->perPage(),
                    'current_page' => $pages->currentPage(),
                    'last_page' => $pages->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve legal pages',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/legal-pages/{id} - Get a specific legal page
     */
    public function show(LegalPage $legalPage): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => [
                    'page' => $legalPage,
                    'versions' => $legalPage->versions()
                        ->orderBy('version_number', 'desc')
                        ->get(),
                    'acceptances_count' => $legalPage->countAcceptances(),
                    'is_active' => $legalPage->isActive(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve legal page',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/legal-pages - Create a new legal page
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'subtitle' => 'nullable|string|max:255',
                'type' => 'required|string|in:terms,privacy,acceptable_use,artist_agreement,copyright,cookies,disclaimer,payment_terms,dmca,accessibility',
                'description' => 'nullable|string',
                'content' => 'required|string',
                'applies_to' => 'required|string|in:all,users,artists,labels,event_organizers',
                'requires_acceptance' => 'boolean',
                'effective_date' => 'nullable|date_format:Y-m-d H:i:s',
                'metadata' => 'nullable|json',
            ]);

            $validated['created_by'] = $request->user()->id;
            $validated['status'] = LegalPage::STATUS_DRAFT;

            $page = LegalPage::create($validated);

            // Record initial version
            LegalPageVersion::create([
                'legal_page_id' => $page->id,
                'version_number' => 1,
                'title' => $page->title,
                'content' => $page->content,
                'created_by' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Legal page created successfully',
                'data' => $page,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create legal page',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/admin/legal-pages/{id} - Update a legal page
     */
    public function update(Request $request, LegalPage $legalPage): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'sometimes|string|max:255',
                'subtitle' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'content' => 'sometimes|string',
                'applies_to' => 'sometimes|string|in:all,users,artists,labels,event_organizers',
                'requires_acceptance' => 'boolean',
                'effective_date' => 'nullable|date_format:Y-m-d H:i:s',
                'metadata' => 'nullable|json',
                'changelog' => 'nullable|string',
                'create_version' => 'boolean',
            ]);

            $shouldCreateVersion = $request->get('create_version', false);

            // If content changed and create_version is true, create new version
            if ($shouldCreateVersion && isset($validated['content']) && $validated['content'] !== $legalPage->content) {
                $legalPage->createNewVersion($request->user(), $validated['changelog'] ?? null);
            }

            $validated['updated_by'] = $request->user()->id;

            $legalPage->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Legal page updated successfully',
                'data' => $legalPage,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update legal page',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/legal-pages/{id}/publish - Publish a legal page
     */
    public function publish(Request $request, LegalPage $legalPage): JsonResponse
    {
        try {
            $validated = $request->validate([
                'effective_date' => 'nullable|date_format:Y-m-d H:i:s',
            ]);

            if (isset($validated['effective_date'])) {
                $legalPage->effective_date = $validated['effective_date'];
            }

            $legalPage->publish($request->user());

            return response()->json([
                'success' => true,
                'message' => 'Legal page published successfully',
                'data' => $legalPage,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to publish legal page',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/legal-pages/{id}/archive - Archive a legal page
     */
    public function archive(Request $request, LegalPage $legalPage): JsonResponse
    {
        try {
            $legalPage->update([
                'status' => LegalPage::STATUS_ARCHIVED,
                'updated_by' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Legal page archived successfully',
                'data' => $legalPage,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to archive legal page',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/legal-pages/{id} - Soft delete a legal page
     */
    public function destroy(LegalPage $legalPage): JsonResponse
    {
        try {
            $legalPage->delete();

            return response()->json([
                'success' => true,
                'message' => 'Legal page deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete legal page',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/legal-pages/{id}/versions - Get all versions of a legal page
     */
    public function getVersions(LegalPage $legalPage): JsonResponse
    {
        try {
            $versions = $legalPage->versions()
                ->orderBy('version_number', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $versions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve versions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/legal-pages/{id}/acceptances - Get acceptance statistics
     */
    public function getAcceptances(Request $request, LegalPage $legalPage): JsonResponse
    {
        try {
            $acceptances = $legalPage->acceptances()
                ->with('user:id,email,display_name')
                ->orderBy('accepted_at', 'desc')
                ->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => $acceptances->items(),
                'meta' => [
                    'total' => $acceptances->total(),
                    'acceptance_rate' => \App\Models\User::count() > 0
                        ? round(($acceptances->total() / \App\Models\User::count()) * 100, 2)
                        : 0,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve acceptances',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
