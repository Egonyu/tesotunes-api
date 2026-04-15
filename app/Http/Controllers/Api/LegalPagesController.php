<?php

namespace App\Http\Controllers\Api;

use App\Models\LegalPage;
use App\Models\LegalPageAcceptance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegalPagesController
{
    /**
     * GET /api/legal-pages - Get all published legal pages
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = LegalPage::published();

            // Filter by type
            if ($request->get('type')) {
                $query->byType($request->get('type'));
            }

            // Filter by applies_to role if user is authenticated
            if ($request->user()) {
                $role = 'all';
                if ($request->user()->isArtist()) {
                    $role = 'artists';
                }
                $query->appliesTo($role);
            } else {
                $query->where('applies_to', LegalPage::APPLIES_TO_ALL);
            }

            $pages = $query->select('id', 'title', 'slug', 'type', 'applies_to', 'requires_acceptance')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $pages,
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
     * GET /api/legal-pages/{slug} - Get a specific published legal page by slug
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        try {
            $page = LegalPage::where('slug', $slug)->published()->first();

            if (! $page) {
                return response()->json([
                    'success' => false,
                    'message' => 'Legal page not found',
                ], 404);
            }

            // Check if user already accepted this version
            $userAccepted = null;
            if ($request->user() && $page->requires_acceptance) {
                $userAccepted = $page->userAccepted($request->user());
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $page->id,
                    'title' => $page->title,
                    'subtitle' => $page->subtitle,
                    'slug' => $page->slug,
                    'type' => $page->type,
                    'type_display' => $page->type_display,
                    'content' => $page->content,
                    'version' => $page->version,
                    'effective_date' => $page->effective_date,
                    'requires_acceptance' => $page->requires_acceptance,
                    'user_accepted' => $userAccepted,
                    'published_at' => $page->published_at,
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
     * POST /api/legal-pages/{id}/accept - User accepts a legal page
     */
    public function accept(Request $request, int $id): JsonResponse
    {
        try {
            if (! $request->user()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $page = LegalPage::published()->find($id);

            if (! $page) {
                return response()->json([
                    'success' => false,
                    'message' => 'Legal page not found',
                ], 404);
            }

            if (! $page->requires_acceptance) {
                return response()->json([
                    'success' => false,
                    'message' => 'This page does not require acceptance',
                ], 400);
            }

            // Check if already accepted this version
            if ($page->userAccepted($request->user())) {
                return response()->json([
                    'success' => true,
                    'message' => 'You have already accepted this version',
                    'data' => ['already_accepted' => true],
                ]);
            }

            // Record acceptance
            $page->recordAcceptance(
                $request->user(),
                $request->ip(),
                $request->userAgent()
            );

            return response()->json([
                'success' => true,
                'message' => 'You have accepted the ' . $page->title,
                'data' => [
                    'page_id' => $page->id,
                    'version' => $page->version,
                    'accepted_at' => now(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record acceptance',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/legal-pages/check-acceptance - Check if user has accepted required legal pages
     */
    public function checkAcceptance(Request $request): JsonResponse
    {
        try {
            if (! $request->user()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $requiredPages = LegalPage::published()
                ->where('requires_acceptance', true)
                ->get();

            $acceptanceStatus = $requiredPages->mapWithKeys(function ($page) use ($request) {
                return [
                    $page->slug => [
                        'accepted' => $page->userAccepted($request->user()),
                        'version' => $page->version,
                        'title' => $page->title,
                    ],
                ];
            });

            $allAccepted = $acceptanceStatus->every(fn ($status) => $status['accepted']);

            return response()->json([
                'success' => true,
                'data' => [
                    'all_accepted' => $allAccepted,
                    'pages' => $acceptanceStatus,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check acceptance status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
