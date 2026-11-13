<?php

namespace App\Modules\Store\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureWalletPinVerified;
use App\Modules\Store\Http\Requests\StoreDealRequest;
use App\Modules\Store\Models\Deal;
use App\Modules\Store\Models\DealClaim;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\DealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealController extends Controller
{
    public function __construct(private readonly DealService $deals) {}

    /** Live deals, the viewer's town first, soonest to end first. */
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user() ?? auth('sanctum')->user();
        $town = trim((string) ($request->query('town') ?: $viewer?->city ?: ''));
        $query = Deal::query()->live()->with('store');
        if ($request->filled('town')) {
            $query->whereRaw('LOWER(town) = ?', [mb_strtolower($town)]);
        } elseif ($town !== '') {
            $query->orderByRaw('CASE WHEN LOWER(town) = ? THEN 0 ELSE 1 END', [mb_strtolower($town)]);
        }
        $page = $query->orderByRaw('valid_until IS NULL')->orderBy('valid_until')->orderByDesc('published_at')
            ->paginate(min(50, max(1, (int) $request->query('per_page', 20))));

        return response()->json([
            'data' => collect($page->items())->map(fn (Deal $deal) => $this->publicArray($deal, $viewer))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'town' => $town ?: null],
        ]);
    }

    public function show(Request $request, Deal $deal): JsonResponse
    {
        $viewer = $request->user() ?? auth('sanctum')->user();
        $deal->load('store');
        abort_unless($deal->isLive() || ($viewer && $deal->store?->canBeManagedBy($viewer)), 404);

        return response()->json(['data' => $this->publicArray($deal, $viewer)]);
    }

    /** Claim a deal; a priced deal is a money movement and sits behind the wallet PIN. */
    public function claim(Request $request, Deal $deal): JsonResponse
    {
        if (! $deal->isFree()) {
            $gate = app(EnsureWalletPinVerified::class)->handle($request, fn () => response()->noContent());
            if ($gate->getStatusCode() !== 204) {
                return $gate;
            }
        }

        $claim = $this->deals->claim($deal, $request->user());

        return response()->json(['data' => $claim->toOwnerArray(), 'message' => 'Deal claimed. Show the code at the counter.'], $claim->wasRecentlyCreated ? 201 : 200);
    }

    public function myClaims(Request $request): JsonResponse
    {
        $claims = DealClaim::query()->where('user_id', $request->user()->id)->with('deal.store')->latest('id')->limit(100)->get();

        return response()->json(['data' => $claims->map(fn (DealClaim $claim) => $claim->toOwnerArray())->values()]);
    }

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:64']]);
        $claim = $this->deals->redeem($data['token'], $request->user());

        return response()->json([
            'data' => [
                'uuid' => $claim->uuid,
                'status' => $claim->status,
                'redeemed_at' => $claim->redeemed_at?->toIso8601String(),
                'credits_spent' => $claim->credits_spent,
                'merchant_ugx' => (float) $claim->merchant_ugx,
                'platform_fee_ugx' => (float) $claim->platform_fee_ugx,
                'deal' => ['uuid' => $claim->deal->uuid, 'title' => $claim->deal->title, 'discount_label' => $claim->deal->discount_label],
                'member' => ['name' => $claim->user?->name],
            ],
            'message' => 'Redeemed. Serve the offer.',
        ]);
    }

    /** Storefronts the signed-in person may post deals for: owned directly, as a user owner, or through their artist. */
    public function myStores(Request $request): JsonResponse
    {
        $user = $request->user();
        $artistId = $user->artist?->id;
        $stores = Store::query()
            ->where(function ($query) use ($user, $artistId) {
                $query->where('user_id', $user->id)
                    ->orWhere(fn ($owner) => $owner->where('owner_type', \App\Models\User::class)->where('owner_id', $user->id));
                if ($artistId) {
                    $query->orWhere(fn ($owner) => $owner->where('owner_type', \App\Models\Artist::class)->where('owner_id', $artistId));
                }
            })
            ->orderBy('name')->get();

        return response()->json(['data' => $stores->map(fn (Store $store) => [
            'id' => $store->id, 'name' => $store->name, 'slug' => $store->slug, 'city' => $store->city,
            'status' => $store->status, 'logo_url' => $store->logo_url, 'is_verified' => (bool) $store->is_verified,
        ])->values()]);
    }

    public function storeDeals(Request $request, Store $store): JsonResponse
    {
        abort_unless($store->canBeManagedBy($request->user()), 403);
        $deals = $store->deals()->latest('id')->get();

        return response()->json(['data' => $deals->map(fn (Deal $deal) => $this->publicArray($deal->setRelation('store', $store), null, true))->values()]);
    }

    public function storeDeal(StoreDealRequest $request, Store $store): JsonResponse
    {
        $deal = $this->deals->create($store, $request->validated(), $request->boolean('publish'));

        return response()->json(['data' => $this->publicArray($deal->setRelation('store', $store), null, true)], 201);
    }

    public function publish(Request $request, Deal $deal): JsonResponse
    {
        $deal->load('store');
        abort_unless($deal->store?->canBeManagedBy($request->user()), 403);

        return response()->json(['data' => $this->publicArray($this->deals->publish($deal), null, true)]);
    }

    private function publicArray(Deal $deal, ?\App\Models\User $viewer, bool $merchant = false): array
    {
        $claim = $viewer ? $deal->claimFor($viewer) : null;
        $data = [
            'uuid' => $deal->uuid,
            'title' => $deal->title,
            'slug' => $deal->slug,
            'discount_label' => $deal->discount_label,
            'terms' => $deal->terms,
            'image_url' => $deal->image_url,
            'town' => $deal->town,
            'credits_price' => $deal->credits_price,
            'ugx_value' => $deal->ugx_value !== null ? (float) $deal->ugx_value : null,
            'valid_from' => $deal->valid_from?->toIso8601String(),
            'valid_until' => $deal->valid_until?->toIso8601String(),
            'claims_left' => $deal->claimsLeft(),
            'state' => $deal->isLive() ? 'open' : 'closed',
            'store' => $deal->store ? ['name' => $deal->store->name, 'slug' => $deal->store->slug, 'logo_url' => $deal->store->logo_url, 'city' => $deal->store->city] : null,
            'claim' => $claim ? ['uuid' => $claim->uuid, 'status' => $claim->status, 'expires_at' => $claim->expires_at?->toIso8601String()] : null,
        ];
        if ($merchant) {
            $data += ['status' => $deal->status, 'claims_count' => $deal->claims_count, 'redemptions_count' => $deal->redemptions_count, 'published_at' => $deal->published_at?->toIso8601String()];
        }

        return $data;
    }
}
