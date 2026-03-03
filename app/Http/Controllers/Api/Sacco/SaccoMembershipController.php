<?php

namespace App\Http\Controllers\Api\Sacco;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaccoMemberResource;
use App\Models\Sacco\SaccoMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaccoMembershipController extends Controller
{
    /**
     * GET /api/sacco/membership — current user's membership
     */
    public function myMembership(Request $request): JsonResponse
    {
        $member = SaccoMember::with(['user:id,username,email', 'savingsAccounts', 'shares'])
            ->withCount('loans')
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $member) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => new SaccoMemberResource($member),
        ]);
    }

    /**
     * POST /api/sacco/join — self-registration for authenticated user
     */
    public function join(Request $request): JsonResponse
    {
        $user = $request->user();

        // Check if already a member
        if (SaccoMember::where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'You are already a SACCO member.'], 422);
        }

        $validated = $request->validate([
            'initial_deposit' => 'nullable|numeric|min:0',
            'initial_shares' => 'nullable|integer|min:0',
            'phone_number' => 'required|string|max:20',
            'payment_method' => 'nullable|string|in:mtn_momo,airtel_money',
        ]);

        $member = SaccoMember::create([
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
            'member_number' => 'MBR'.now()->format('Ymd').rand(10000, 99999),
        ]);

        $member->load('user:id,username,email');

        return response()->json([
            'data' => new SaccoMemberResource($member),
            'message' => 'Welcome to TesoTunes SACCO!',
        ], 201);
    }

    /**
     * GET /api/sacco/members — list members
     */
    public function index(Request $request)
    {
        $query = SaccoMember::with('user:id,username,email');

        if ($search = $request->get('search')) {
            $escaped = escape_like($search);
            $query->where(function ($q) use ($escaped) {
                $q->where('member_number', 'like', "%{$escaped}%")
                    ->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$escaped}%")->orWhere('email', 'like', "%{$escaped}%"));
            });
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $members = $query->latest('joined_at')
            ->paginate($this->getPerPage($request));

        return SaccoMemberResource::collection($members);
    }

    /**
     * POST /api/sacco/members — register member
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id|unique:sacco_members,user_id',
            'member_type' => 'nullable|string|in:regular,premium',
            'id_number' => 'nullable|string|max:50',
            'id_type' => 'nullable|string|in:national_id,passport,driving_permit',
            'date_of_birth' => 'nullable|date|before:today',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
        ]);

        $member = SaccoMember::create([
            ...$validated,
            'status' => 'active',
            'joined_at' => now(),
            'member_number' => 'MBR'.now()->format('Ymd').rand(10000, 99999),
        ]);

        $member->load('user:id,username,email');

        return response()->json([
            'data' => new SaccoMemberResource($member),
            'message' => 'Member registered successfully.',
        ], 201);
    }

    /**
     * GET /api/sacco/members/{member} — member detail
     */
    public function show($member)
    {
        $member = SaccoMember::with(['user:id,username,email', 'savingsAccounts', 'shares'])
            ->withCount('loans')
            ->findOrFail($member);

        return new SaccoMemberResource($member);
    }

    /**
     * PUT /api/sacco/members/{member} — update member
     */
    public function update(Request $request, $member): JsonResponse
    {
        $member = SaccoMember::findOrFail($member);

        $validated = $request->validate([
            'member_type' => 'nullable|string|in:regular,premium',
            'id_number' => 'nullable|string|max:50',
            'id_type' => 'nullable|string|in:national_id,passport,driving_permit',
            'date_of_birth' => 'nullable|date|before:today',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
        ]);

        $member->update(array_filter($validated, fn ($v) => $v !== null));
        $member->load('user:id,username,email');

        return response()->json([
            'data' => new SaccoMemberResource($member),
            'message' => 'Member updated successfully.',
        ]);
    }

    /**
     * PATCH /api/sacco/members/{member}/status — update status
     */
    public function updateStatus(Request $request, $member): JsonResponse
    {
        $member = SaccoMember::findOrFail($member);

        $validated = $request->validate([
            'status' => 'required|string|in:active,suspended,resigned,deceased',
        ]);

        $member->update(['status' => $validated['status']]);

        return response()->json([
            'data' => new SaccoMemberResource($member),
            'message' => 'Member status updated to '.$validated['status'].'.',
        ]);
    }
}
