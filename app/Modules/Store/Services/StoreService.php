<?php

namespace App\Modules\Store\Services;

use App\Modules\Store\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

/**
 * StoreService
 * 
 * Handles all business logic for store management
 */
class StoreService
{
    /**
     * Create a new store
     */
    public function create(User $user, array $data): Store
    {
        $this->validateStoreCreation($user);
        
        return DB::transaction(function () use ($user, $data) {
            $store = Store::create([
                'uuid' => Str::uuid(),
                'user_id' => $user->id,
                'owner_id' => $user->id,
                'owner_type' => User::class,
                'name' => $data['name'],
                'slug' => $data['slug'] ?? $this->generateUniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'store_type' => $user->hasRole('artist') ? Store::TYPE_ARTIST : Store::TYPE_USER,
                'status' => 'pending',
                'subscription_tier' => Store::TIER_FREE,
                'settings' => Store::getDefaultSettings(),
            ]);
            
            // Handle media uploads
            if (isset($data['logo'])) {
                $this->uploadLogo($store, $data['logo']);
            }
            
            if (isset($data['banner'])) {
                $this->uploadBanner($store, $data['banner']);
            }
            
            return $store;
        });
    }

    /**
     * Update store
     */
    public function update(Store $store, array $data): Store
    {
        return DB::transaction(function () use ($store, $data) {
            $updateData = [];
            
            if (isset($data['name'])) {
                $updateData['name'] = $data['name'];
                $updateData['slug'] = $this->generateUniqueSlug($data['name'], $store->id);
            }
            
            if (isset($data['description'])) {
                $updateData['description'] = $data['description'];
            }
            
            if (isset($data['settings'])) {
                $updateData['settings'] = array_merge($store->settings ?? [], $data['settings']);
            }
            
            if (!empty($updateData)) {
                $store->update($updateData);
            }
            
            // Handle media updates
            if (isset($data['logo'])) {
                $this->uploadLogo($store, $data['logo']);
            }
            
            if (isset($data['banner'])) {
                $this->uploadBanner($store, $data['banner']);
            }
            
            return $store->fresh();
        });
    }

    /**
     * Activate store
     */
    public function activate(Store $store): bool
    {
        $store->update([
            'status' => 'active',
            'suspended_reason' => null,
            'suspended_at' => null,
        ]);
        
        return true;
    }

    /**
     * Suspend store
     */
    public function suspend(Store $store, string $reason): bool
    {
        $store->update([
            'status' => 'suspended',
            'suspended_reason' => $reason,
            'suspended_at' => now(),
        ]);
        
        return true;
    }
    
    /**
     * Verify store
     */
    public function verify(Store $store): bool
    {
        $store->update([
            'is_verified' => true,
            'verified_at' => now(),
        ]);
        
        return true;
    }
    
    /**
     * Calculate store revenue
     */
    public function calculateRevenue(Store $store): array
    {
        $orders = $store->orders()
            ->whereIn('status', ['completed', 'delivered'])
            ->where('payment_status', 'paid')
            ->get();
            
        return [
            'total' => $orders->sum('total_ugx'),
            'orders_count' => $orders->count(),
        ];
    }
    
    /**
     * Calculate store commission
     */
    public function calculateCommission(Store $store): float
    {
        return $store->orders()
            ->whereIn('status', ['completed', 'delivered'])
            ->sum('platform_fee_ugx');
    }
    
    /**
     * Get store analytics
     */
    public function getAnalytics(Store $store, int $days = 30): array
    {
        $orders = $store->orders()
            ->where('created_at', '>=', now()->subDays($days));
            
        return [
            'total_revenue' => $orders->whereIn('status', ['completed', 'delivered'])->sum('total_ugx'),
            'total_orders' => $orders->count(),
            'pending_orders' => $orders->where('status', 'pending')->count(),
            'completed_orders' => $orders->where('status', 'completed')->count(),
            'cancelled_orders' => $orders->where('status', 'cancelled')->count(),
            'total_products' => $store->products()->count(),
            'views' => $store->views_count ?? 0,
        ];
    }

    /**
     * Close store permanently
     */
    public function close(Store $store, string $reason = null): bool
    {
        return $store->close($reason);
    }

    /**
     * Update subscription
     */
    public function updateSubscription(Store $store, string $tier, array $paymentData = []): bool
    {
        return DB::transaction(function () use ($store, $tier, $paymentData) {
            $price = match($tier) {
                Store::TIER_PREMIUM => config('store.subscriptions.premium.price_ugx'),
                Store::TIER_BUSINESS => config('store.subscriptions.business.price_ugx'),
                default => 0,
            };
            
            $store->update([
                'subscription_tier' => $tier,
                'subscription_expires_at' => $tier === Store::TIER_FREE ? null : now()->addMonth(),
            ]);
            
            // Create subscription record if not free
            if ($tier !== Store::TIER_FREE) {
                $store->subscription()->create([
                    'plan' => $tier,
                    'status' => 'active',
                    'price_ugx' => $price,
                    'billing_cycle' => 'monthly',
                    'starts_at' => now(),
                    'expires_at' => now()->addMonth(),
                ]);
            }
            
            return true;
        });
    }

    /**
     * Validate store creation
     */
    protected function validateStoreCreation(User $user): void
    {
        if (!config('store.enabled', false)) {
            throw new \Exception('Store module is currently disabled');
        }
        
        if ($user->store()->exists()) {
            throw new \Exception('User already has a store');
        }
        
        if (!$user->email_verified_at) {
            throw new \Exception('Email must be verified to create a store');
        }
        
        if (!$user->hasRole('artist') && !config('store.stores.allow_user_stores', false)) {
            throw new \Exception('Only artists can create stores');
        }
    }

    /**
     * Generate unique slug
     */
    protected function generateUniqueSlug(string $name, ?int $excludeId = null): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;
        
        while (Store::where('slug', $slug)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->exists()) {
            $slug = $originalSlug . '-' . $counter++;
        }
        
        return $slug;
    }

    /**
     * Upload store logo
     */
    protected function uploadLogo(Store $store, $file): void
    {
        $disk = config('store.storage.disk');
        $path = Storage::disk($disk)->putFile(
            config('store.storage.paths.store_logos') . '/' . $store->uuid,
            $file
        );
        
        // Delete old logo if exists
        if ($store->logo) {
            Storage::disk($disk)->delete($store->logo);
        }
        
        $store->update(['logo' => $path]);
    }

    /**
     * Upload store banner
     */
    protected function uploadBanner(Store $store, $file): void
    {
        $disk = config('store.storage.disk');
        $path = Storage::disk($disk)->putFile(
            config('store.storage.paths.store_banners') . '/' . $store->uuid,
            $file
        );
        
        // Delete old banner if exists
        if ($store->banner) {
            Storage::disk($disk)->delete($store->banner);
        }
        
        $store->update(['banner' => $path]);
    }

    /**
     * Get store statistics
     */
    public function getStatistics(Store $store): array
    {
        // Get weekly sales (last 7 days)
        $weeklySales = $this->getWeeklySales($store);
        
        // Get daily sales (last 7 days)
        $dailySales = $this->getDailySales($store);
        
        // Calculate order growth
        $orderGrowth = $this->calculateOrderGrowth($store);
        
        return [
            'total_sales_ugx' => $store->total_sales_ugx,
            'total_sales_credits' => $store->total_sales_credits,
            'total_orders' => $store->total_orders,
            'products_count' => $store->products()->count(),
            'active_products' => $store->activeProducts()->count(),
            'pending_orders' => $store->orders()->pending()->count(),
            'average_rating' => $store->average_rating,
            'reviews_count' => $store->reviews_count,
            'weekly_sales' => $weeklySales,
            'daily_sales' => $dailySales,
            'order_growth' => $orderGrowth,
        ];
    }
    
    /**
     * Get weekly sales data (last 7 days)
     */
    protected function getWeeklySales(Store $store): array
    {
        $sales = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dailyTotal = $store->orders()
                ->whereDate('created_at', $date)
                ->sum('total_amount');
            $sales[] = max(10, (int)($dailyTotal / 1000)); // Convert to thousands, min 10 for visibility
        }
        return $sales;
    }
    
    /**
     * Get daily sales data (last 7 days) 
     */
    protected function getDailySales(Store $store): array
    {
        $sales = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dailyTotal = $store->orders()
                ->whereDate('created_at', $date)
                ->sum('total_amount');
            $sales[] = max(10, (int)($dailyTotal / 1000)); // Convert to thousands
        }
        return $sales;
    }
    
    /**
     * Calculate order growth (this week vs last week)
     */
    protected function calculateOrderGrowth(Store $store): float
    {
        $thisWeek = $store->orders()
            ->where('created_at', '>=', now()->subWeek())
            ->count();
            
        $lastWeek = $store->orders()
            ->where('created_at', '>=', now()->subWeeks(2))
            ->where('created_at', '<', now()->subWeek())
            ->count();
            
        if ($lastWeek === 0) {
            return $thisWeek > 0 ? 100 : 0;
        }
        
        return round((($thisWeek - $lastWeek) / $lastWeek) * 100, 1);
    }
}
