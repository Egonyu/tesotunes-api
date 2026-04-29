<?php

namespace App\Models;

use App\Helpers\StorageHelper;
use App\Modules\Podcast\Traits\HasPodcast;
use App\Modules\Sacco\Traits\HasSaccoMembership;
use App\Modules\Store\Traits\HasStore;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasPodcast, HasSaccoMembership, HasStore, Notifiable, SoftDeletes;

    /**
     * Temporary storage for credit balance before user creation
     */
    protected static $pendingCreditBalances = [];

    protected static ?bool $hasArtistProfilesTable = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        // Authentication (note: 'name' maps to 'display_name' via mutator)
        'name',
        'username',
        'email',
        'password',
        // 'email_verified_at', // REMOVED: HIGH-4 — use markEmailAsVerified() method, not mass assignment

        // Social Authentication (NEW)
        'provider',
        'provider_id',
        'provider_token',
        'provider_refresh_token',

        // Profile Completion (NEW)
        'profile_completion_percentage',
        'profile_steps_completed',

        // Phone verification
        'phone',
        'phone_verified_at',

        // Identity Verification (Artist KYC)
        'full_name',
        'nin_number',
        'national_id_front_path',
        'national_id_back_path',
        'selfie_with_id_path',

        // Profile (including artist fields)
        'avatar',
        'bio',
        'display_name',
        'stage_name',
        'first_name',
        'last_name',
        'banner',
        'gender',

        // Location
        'country',
        'city',
        'timezone',
        'date_of_birth',
        'language',

        // User role & status (privilege fields NOT mass-assignable — use dedicated methods)
        'is_artist',
        // 'is_active',     // REMOVED: HIGH-4 privilege escalation risk — use setActive()/suspend() methods
        'is_verified',
        // 'is_premium',    // REMOVED: HIGH-4 privilege escalation risk — use subscription system
        'status',
        'application_status',

        // Status & verification
        // 'is_active',     // REMOVED: duplicate & privilege escalation risk
        'is_online',
        'last_seen_at',
        'verified_at',
        'verified_by',
        'rejection_reason',

        // Activity tracking
        'last_login_at',
        'last_admin_login_at',
        'ip_address',
        'user_agent',

        // Preferences
        'preferred_language',
        'two_factor_enabled',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'email_notifications_enabled',
        'sms_notifications_enabled',
        'notification_preferences',
        // 'permissions',   // REMOVED: HIGH-4 privilege escalation risk — use role/permission system
        'settings',

        // Payment info
        'payment_method',
        'mobile_money_number',
        'mobile_money_provider',

        // Credits system (privilege fields — NOT mass-assignable, use CreditService methods)
        // 'credits',       // REMOVED: HIGH-4 — use addCredits()/deductCredits() methods
        // 'ugx_balance',   // REMOVED: HIGH-4 — use financial transaction methods

        // Referral system
        'referral_code',
        'referrer_id',
        'referral_count',
        'referred_at',

        // Social links (correct field names from database)
        'instagram_url',
        'twitter_url',
        'facebook_url',
        'youtube_url',
        'tiktok_url',

        // Theme preference
        'theme_preference',

        // Meta
        'created_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'phone',
        'nin_number',
        'date_of_birth',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'ip_address',
        'user_agent',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'last_admin_login_at' => 'datetime',
            'is_online' => 'boolean',
            'last_seen_at' => 'datetime',
            'date_of_birth' => 'date',
            'two_factor_enabled' => 'boolean',
            'email_notifications_enabled' => 'boolean',
            'sms_notifications_enabled' => 'boolean',
            'notification_preferences' => 'array',
            'permissions' => 'array',
            'settings' => 'array',
            'social_links' => 'array',
            // NEW: Social auth and profile completion
            'profile_steps_completed' => 'array',
            'profile_completion_percentage' => 'integer',
            // Credits system
            'credits' => 'integer',
            'ugx_balance' => 'decimal:2',
            // Referral system
            'referrer_id' => 'integer',
            'referral_count' => 'integer',
            'referred_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->uuid)) {
                $user->uuid = \Illuminate\Support\Str::uuid();
            }
        });

        static::created(function (User $user) {
            if (Schema::hasTable('user_profiles')) {
                UserProfile::createDefault($user);
            }

            if (Schema::hasTable('user_security_profiles')) {
                UserSecurityProfile::createDefault($user);
            }

            if (Schema::hasTable('user_referrals')) {
                UserReferral::createDefault($user);
            }

            // Auto-create default user settings
            // Disabled - user_settings uses key-value structure
            // if (!$user->settings) {
            //     UserSetting::createDefault($user);
            // }

            // Set credit balance from temporary storage if set
            $hash = spl_object_hash($user);
            if (isset(self::$pendingCreditBalances[$hash])) {
                $balance = self::$pendingCreditBalances[$hash];
                unset(self::$pendingCreditBalances[$hash]);

                $wallet = $user->creditWallet()->firstOrCreate(
                    ['user_id' => $user->id],
                    ['balance' => $balance]
                );
                if ($wallet->balance != $balance) {
                    $wallet->balance = $balance;
                    $wallet->save();
                }
            }
        });
    }

    // Relationships

    // NEW: Core user relationships for normalized structure
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function securityProfile(): HasOne
    {
        return $this->hasOne(UserSecurityProfile::class);
    }

    public function artistProfile(): HasOne
    {
        return $this->hasOne(ArtistProfile::class);
    }

    public function referralProfile(): HasOne
    {
        return $this->hasOne(UserReferral::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(UserVerification::class);
    }

    public function artist(): HasOne
    {
        return $this->hasOne(Artist::class);
    }

    /**
     * Get the user's platform loyalty points.
     */
    public function loyaltyPoints(): HasOne
    {
        return $this->hasOne(LoyaltyPoints::class);
    }

    /**
     * Get the user's loyalty card memberships.
     */
    public function loyaltyCardMemberships(): HasMany
    {
        return $this->hasMany(\App\Models\Loyalty\LoyaltyCardMember::class);
    }

    public function saccoMember(): HasOne
    {
        return $this->hasOne(\App\Models\Sacco\SaccoMember::class);
    }

    public function saccoMembership(): HasOne
    {
        return $this->saccoMember();
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(UserSubscription::class)
            ->where('status', 'active')
            ->latest();
    }

    public function activeSubscription()
    {
        return $this->hasOne(UserSubscription::class)
            ->where('status', 'active')
            ->latest();
    }

    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(\App\Modules\Store\Models\Order::class);
    }

    public function playHistory(): HasMany
    {
        return $this->hasMany(PlayHistory::class);
    }

    public function playQueue(): HasMany
    {
        return $this->hasMany(PlayQueue::class);
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    public function likedSongs(): BelongsToMany
    {
        $relationship = $this->belongsToMany(\App\Models\Song::class, 'likes', 'user_id', 'likeable_id')
            ->wherePivot('likeable_type', \App\Models\Song::class);

        if (Schema::hasColumn('likes', 'liked_at')) {
            $relationship->withPivot('liked_at');
        }

        return $relationship;
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class);
    }

    // notifications() provided by Notifiable trait (morphMany via notifiable_type/notifiable_id)

    /**
     * Get user's saved addresses
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class)->orderBy('is_default', 'desc')->orderBy('created_at', 'desc');
    }

    /**
     * Get user's default address
     */
    public function defaultAddress(): HasOne
    {
        return $this->hasOne(Address::class)->where('is_default', true);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(Download::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * Get user's songs through their artist profile
     * Uses hasManyThrough to get songs via the artist relationship
     *
     * Note: When filtering by 'status', always qualify the column name
     * Use: $user->songs()->where('songs.status', 'published')
     * Not: $user->songs()->where('status', 'published')
     */
    public function songs(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            Song::class,      // Final model we want to access
            Artist::class,    // Intermediate model
            'user_id',        // Foreign key on artists table
            'artist_id',      // Foreign key on songs table
            'id',             // Local key on users table
            'id'              // Local key on artists table
        );
    }

    // KYC Documents relationship (NEW)
    public function kycDocuments(): HasMany
    {
        return $this->hasMany(KYCDocument::class);
    }

    // Following relationships
    public function following(): HasMany
    {
        return $this->hasMany(UserFollow::class, 'follower_id');
    }

    public function interestedEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_interests', 'user_id', 'event_id')
            ->withTimestamps();
    }

    public function followers(): HasMany
    {
        return $this->hasMany(UserFollow::class, 'following_id');
    }

    public function followedArtists(): BelongsToMany
    {
        // Note: This joins users via user_follows where following_id is the artist's user_id
        return $this->belongsToMany(User::class, 'user_follows', 'follower_id', 'following_id')
            ->whereHas('artist'); // Only get users who are artists
    }

    public function collaboratingPlaylists(): HasMany
    {
        return $this->hasMany(PlaylistCollaborator::class)
            ->where('status', 'accepted');
    }

    // Role-based relationships
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['assigned_at', 'assigned_by'])
            ->withTimestamps();
    }

    /**
     * Get the user's primary role name.
     * Checks the user_roles pivot table first, then falls back to the direct
     * 'role' column on the users table for backward compatibility.
     */
    public function getRoleAttribute(): ?string
    {
        $role = $this->activeRoles()->orderByDesc('priority')->first();

        if ($role) {
            return $role->name;
        }

        // Fallback: prefer direct column to avoid surfacing inactive pivot roles.
        if (! empty($this->attributes['role'])) {
            return $this->attributes['role'];
        }

        return $this->roles()->orderByDesc('priority')->value('name');
    }

    public function activeRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['assigned_at', 'assigned_by', 'is_active'])
            ->wherePivot('is_active', 1)
            ->withTimestamps();
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    // Referral system relationships
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referrer_id');
    }

    /**
     * Generate a unique referral code for the user
     */
    public function generateReferralCode(): string
    {
        if ($this->referral_code) {
            return $this->referral_code;
        }

        do {
            $code = strtoupper(substr(md5(uniqid()), 0, 4).'-'.$this->id);
        } while (
            self::where('referral_code', $code)->exists()
            || UserReferral::where('referral_code', $code)->exists()
        );

        $this->update(['referral_code' => $code]);
        $this->referralProfile()->updateOrCreate(
            ['user_id' => $this->id],
            ['referral_code' => $code]
        );

        return $code;
    }

    /**
     * Get the user's referral link
     */
    public function getReferralLinkAttribute(): string
    {
        $code = $this->referral_code ?? $this->generateReferralCode();

        return url('/register?ref='.$code);
    }

    /**
     * Check if user was referred
     */
    public function wasReferred(): bool
    {
        return ! is_null($this->referrer_id);
    }

    /**
     * Record a successful referral
     */
    public function recordReferral(User $referredUser): void
    {
        $this->increment('referral_count');
        $updatedReferralCount = (int) $this->fresh()->getRawOriginal('referral_count');

        $this->referralProfile()->updateOrCreate(
            ['user_id' => $this->id],
            ['referral_count' => $updatedReferralCount]
        );

        $referredUser->update([
            'referrer_id' => $this->id,
            'referred_at' => now(),
        ]);
        $referredUser->referralProfile()->updateOrCreate(
            ['user_id' => $referredUser->id],
            [
                'referrer_id' => $this->id,
                'referred_at' => now(),
            ]
        );
    }

    // Credit system relationships
    public function creditWallet(): HasOne
    {
        return $this->hasOne(UserCredit::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function ensureCreditWallet(): UserCredit
    {
        return $this->creditWallet()->firstOrCreate(
            ['user_id' => $this->id],
            ['balance' => 0, 'currency' => 'credits']
        );
    }

    public function addCredits(float $amount, string $source = 'system', string $description = 'Credits added', array $metadata = []): CreditTransaction
    {
        return $this->ensureCreditWallet()->addCredits($amount, $source, $description, $metadata);
    }

    public function spendCredits(float $amount, string $source = 'system', string $description = 'Credits spent', array $metadata = []): ?CreditTransaction
    {
        return $this->ensureCreditWallet()->spendCredits($amount, $source, $description, $metadata);
    }

    public function creditActivities(): HasMany
    {
        return $this->hasMany(UserActivityCredit::class);
    }

    public function getEventOrganizerAttribute(): array
    {
        return $this->getEventOrganizerProfile();
    }

    public function getEventOrganizerProfile(): array
    {
        return $this->normalizeEventOrganizerProfile($this->eventOrganizerProfileAttributes());
    }

    public function isEventOrganizer(): bool
    {
        return (bool) ($this->getEventOrganizerProfile()['enabled'] ?? false);
    }

    public function syncEventOrganizerProfile(array $attributes): void
    {
        $profile = $this->normalizeEventOrganizerProfile($attributes);

        $settings = $this->rawJsonSettings();
        $settings['event_organizer'] = $profile;

        $this->forceFill(['settings' => $settings])->save();

        $userSetting = $this->settings()->firstOrCreate(['user_id' => $this->id]);
        $privacySettings = $userSetting->privacy_settings;

        if (! is_array($privacySettings)) {
            $privacySettings = [];
        }

        $privacySettings['event_organizer'] = $profile;

        $userSetting->forceFill([
            'privacy_settings' => $privacySettings,
        ])->save();

        $this->unsetRelation('settings');
    }

    private function eventOrganizerProfileAttributes(): array
    {
        $settings = $this->rawJsonSettings();
        $profile = $settings['event_organizer'] ?? null;

        if (is_array($profile)) {
            return $profile;
        }

        $settingsRelation = $this->relationLoaded('settings')
            ? $this->getRelation('settings')
            : $this->settings()->first();

        if ($settingsRelation instanceof UserSetting) {
            $privacySettings = $settingsRelation->privacy_settings;

            if (is_array($privacySettings) && isset($privacySettings['event_organizer']) && is_array($privacySettings['event_organizer'])) {
                return $privacySettings['event_organizer'];
            }
        }

        return [];
    }

    private function rawJsonSettings(): array
    {
        $rawSettings = $this->getRawOriginal('settings');

        if (is_string($rawSettings) && $rawSettings !== '') {
            $decoded = json_decode($rawSettings, true);

            return is_array($decoded) ? $decoded : [];
        }

        if (is_array($rawSettings)) {
            return $rawSettings;
        }

        $attribute = $this->getAttributeFromArray('settings');

        return is_array($attribute) ? $attribute : [];
    }

    private function normalizeEventOrganizerProfile(array $profile): array
    {
        return [
            'enabled' => (bool) ($profile['enabled'] ?? false),
            'business_name' => $profile['business_name'] ?? null,
            'support_email' => $profile['support_email'] ?? null,
            'support_phone' => $profile['support_phone'] ?? null,
            'notes' => $profile['notes'] ?? null,
            'payout_method' => $profile['payout_method'] ?? 'mobile_money',
            'mobile_money_provider' => $profile['mobile_money_provider'] ?? null,
            'mobile_money_number' => $profile['mobile_money_number'] ?? null,
            'bank_name' => $profile['bank_name'] ?? null,
            'bank_account' => $profile['bank_account'] ?? null,
        ];
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    // Forum & Polls module relationships
    public function subscribedForumCategories(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\Modules\Forum\ForumCategory::class,
            'forum_category_subscriptions',
            'user_id',
            'category_id'
        )->select('forum_categories.*')->withTimestamps();
    }

    public function forumTopics(): HasMany
    {
        return $this->hasMany(\App\Models\Modules\Forum\ForumTopic::class);
    }

    public function forumReplies(): HasMany
    {
        return $this->hasMany(\App\Models\Modules\Forum\ForumReply::class);
    }

    public function polls(): HasMany
    {
        return $this->hasMany(\App\Models\Modules\Forum\Poll::class);
    }

    // Store module relationships
    public function storeOrders(): HasMany
    {
        return $this->hasMany(\App\Modules\Store\Models\Order::class, 'user_id');
    }

    public function storeProducts(): HasManyThrough
    {
        return $this->hasManyThrough(
            \App\Modules\Store\Models\Product::class,
            \App\Modules\Store\Models\Store::class,
            'user_id',
            'store_id'
        );
    }

    public function stores(): HasMany
    {
        return $this->hasMany(\App\Modules\Store\Models\Store::class, 'user_id');
    }

    public function store(): HasOne
    {
        return $this->hasOne(\App\Modules\Store\Models\Store::class, 'user_id')->latestOfMany();
    }

    public function cart(): HasOne
    {
        return $this->hasOne(\App\Modules\Store\Models\Cart::class);
    }

    // Record Label relationships
    public function recordLabels(): BelongsToMany
    {
        return $this->belongsToMany(RecordLabel::class, 'record_label_members', 'user_id', 'record_label_id')
            ->withPivot('role', 'permissions', 'invited_at', 'accepted_at', 'is_active')
            ->wherePivot('is_active', true);
    }

    public function ownedLabels(): HasMany
    {
        return $this->hasMany(RecordLabel::class, 'owner_id');
    }

    public function labelApplication(): HasOne
    {
        return $this->hasOne(LabelApplication::class)->latest();
    }

    public function labelApplications(): HasMany
    {
        return $this->hasMany(LabelApplication::class);
    }

    // Artist-specific relationships (delegated to Artist model)
    public function primaryGenre()
    {
        return $this->artist?->primaryGenre();
    }

    // Accessors & Mutators

    /**
     * Get the user's name (backward compatibility)
     * Falls back to display_name since 'name' column doesn't exist in DB
     */
    public function getNameAttribute($value)
    {
        // If there's a value in the DB, use it
        if ($value !== null && $value !== '') {
            return $value;
        }

        // Otherwise fall back to display_name accessor
        return $this->getDisplayNameAttribute();
    }

    /**
     * Set the user's name - maps to display_name since 'name' column doesn't exist
     */
    public function setNameAttribute($value)
    {
        $this->attributes['display_name'] = $value;
    }

    // Backward compatibility accessors for profile fields (NEW)
    public function getBioAttribute($value)
    {
        return $this->profileValue('bio', $value);
    }

    public function getAvatarAttribute($value)
    {
        return $this->profileValue('avatar', $value);
    }

    public function getDateOfBirthAttribute($value)
    {
        return $this->profileValue('date_of_birth', $value);
    }

    public function getBannerAttribute($value)
    {
        return $this->profileValue('banner', $value);
    }

    public function getCountryAttribute($value)
    {
        return $this->profileValue('country', $value);
    }

    public function getCityAttribute($value)
    {
        return $this->profileValue('city', $value);
    }

    public function getTimezoneAttribute($value)
    {
        return $this->profileValue('timezone', $value);
    }

    public function getLanguageAttribute($value)
    {
        return $this->profileValue('language', $value);
    }

    public function getInstagramUrlAttribute($value)
    {
        return $this->profileValue('instagram_url', $value);
    }

    public function getTwitterUrlAttribute($value)
    {
        return $this->profileValue('twitter_url', $value);
    }

    public function getFacebookUrlAttribute($value)
    {
        return $this->profileValue('facebook_url', $value);
    }

    public function getYoutubeUrlAttribute($value)
    {
        return $this->profileValue('youtube_url', $value);
    }

    public function getTiktokUrlAttribute($value)
    {
        return $this->profileValue('tiktok_url', $value);
    }

    public function getProfileCompletionPercentageAttribute($value)
    {
        return $this->profileValue('profile_completion_percentage', $value);
    }

    public function getProfileStepsCompletedAttribute($value)
    {
        return $this->profileValue('profile_steps_completed', $value);
    }

    public function getReferralCodeAttribute($value)
    {
        return $this->referralValue('referral_code', $value);
    }

    public function getReferrerIdAttribute($value)
    {
        return $this->referralValue('referrer_id', $value);
    }

    public function getReferralCountAttribute($value)
    {
        return $this->referralValue('referral_count', $value);
    }

    public function getReferredAtAttribute($value)
    {
        return $this->referralValue('referred_at', $value);
    }

    public function getThemePreferenceAttribute($value): string
    {
        return (string) ($this->settingsValue('theme', $value ?? 'system') ?? 'system');
    }

    public function getSettingsAttribute($value): mixed
    {
        if ($this->relationLoaded('settings')) {
            return $this->getRelation('settings');
        }

        if ($this->exists) {
            $setting = $this->settings()->first();
            if ($setting) {
                return $setting;
            }
        }

        return $value;
    }

    public function getNotificationPreferencesAttribute($value): mixed
    {
        return $this->settingsValue('notification_preferences', $value);
    }

    public function getEmailNotificationsEnabledAttribute($value): bool
    {
        return (bool) $this->settingsValue('email_notifications', $value ?? true);
    }

    public function getSmsNotificationsEnabledAttribute($value): bool
    {
        return (bool) $this->settingsValue('sms_notifications', $value ?? true);
    }

    public function getAvatarUrlAttribute(): string
    {
        $avatar = $this->attributes['avatar'] ?? null;

        return StorageHelper::avatarUrl($avatar, $this->name);
    }

    public function getIsArtistAttribute(): bool
    {
        return $this->artist !== null;
    }

    public function getFollowerCountAttribute(): int
    {
        return $this->followers()->count();
    }

    public function getFollowingCountAttribute(): int
    {
        return $this->following()->count();
    }

    public function getIsOnlineAttribute(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(5));
    }

    public function getLastSeenFormattedAttribute(): string
    {
        if (! $this->last_seen_at) {
            return 'Never';
        }

        if ($this->is_online) {
            return 'Online';
        }

        return $this->last_seen_at->diffForHumans();
    }

    public function getCreditBalanceAttribute(): int
    {
        return $this->creditWallet?->balance ?? 0;
    }

    public function getCreditsAttribute($value): int
    {
        if ($this->relationLoaded('creditWallet') && $this->creditWallet) {
            return (int) $this->creditWallet->balance;
        }

        if ($this->exists) {
            $walletBalance = $this->creditWallet()->value('balance');
            if ($walletBalance !== null) {
                return (int) $walletBalance;
            }
        }

        return (int) ($value ?? 0);
    }

    public function setCreditBalanceAttribute($value): void
    {
        // Only set if user already exists (has ID)
        if ($this->exists && $this->id) {
            $wallet = $this->creditWallet()->firstOrCreate(
                ['user_id' => $this->id],
                ['balance' => 0]
            );
            $wallet->balance = $value;
            $wallet->save();
        }
        // Store temporarily for creation using object hash
        else {
            self::$pendingCreditBalances[spl_object_hash($this)] = $value;
        }
    }

    // Helper methods
    public function isFollowing(User $user): bool
    {
        return $this->following()
            ->where('following_id', $user->id)
            ->exists();
    }

    public function follow(User $user): void
    {
        if (! $this->isFollowing($user)) {
            $this->following()->create([
                'following_id' => $user->id,
                'type' => 'user',
            ]);

            // Create notification
            Notification::createRichForUser(
                $user,
                'new_follower',
                'New Follower',
                "{$this->name} started following you",
                ['follower_id' => $this->id],
                Route::has('user.profile') ? route('user.profile', $this->id) : url("/users/{$this->id}"),
                'social',
                $this,
                $this->id
            );
        }
    }

    public function unfollow(User $user): void
    {
        $this->following()
            ->where('following_id', $user->id)
            ->delete();
    }

    public function hasLiked($likeable): bool
    {
        return $this->likes()
            ->where('likeable_type', get_class($likeable))
            ->where('likeable_id', $likeable->id)
            ->exists();
    }

    public function like($likeable): void
    {
        if (! $this->hasLiked($likeable)) {
            $this->likes()->create([
                'likeable_type' => get_class($likeable),
                'likeable_id' => $likeable->id,
            ]);

            // Increment like count
            $likeable->increment('like_count');
        }
    }

    public function unlike($likeable): void
    {
        $this->likes()
            ->where('likeable_type', get_class($likeable))
            ->where('likeable_id', $likeable->id)
            ->delete();

        // Decrement like count
        $likeable->decrement('like_count');
    }

    public function updateOnlineStatus(): void
    {
        $this->update([
            'is_online' => true,
            'last_seen_at' => now(),
        ]);
    }

    // African market specific methods
    public function hasActiveSubscription(): bool
    {
        $sub = $this->subscription;

        return $sub
            && $sub->status === 'active'
            && $sub->expires_at
            && $sub->expires_at->isFuture();
    }

    public function getActivePlan(): ?SubscriptionPlan
    {
        if (! $this->hasActiveSubscription()) {
            return null;
        }

        return $this->subscription->subscriptionPlan;
    }

    public function getPlanLimit(string $key, mixed $default = null): mixed
    {
        $plan = $this->getActivePlan();

        if (! $plan) {
            return $default;
        }

        // Check direct column first, then limits JSON
        if (isset($plan->{$key}) && $plan->{$key} !== null) {
            return $plan->{$key};
        }

        return $plan->limits[$key] ?? $default;
    }

    public function canDownload(): bool
    {
        $limit = $this->getPlanLimit('max_downloads_per_day', 3);

        // -1 or null means unlimited for paid plans
        if ($limit === null || $limit === -1) {
            return true;
        }

        $todayDownloads = Download::where('user_id', $this->id)
            ->whereDate('downloaded_at', today())
            ->count();

        return $todayDownloads < $limit;
    }

    public function hasPurchasedSong(Song $song): bool
    {
        return SongPurchase::where('user_id', $this->id)
            ->where('song_id', $song->id)
            ->exists();
    }

    public function canPlayPremiumContent(): bool
    {
        // Check if user has active subscription or premium credits
        return $this->hasActiveSubscription();
    }

    /**
     * Check if user can stream music.
     * All users can stream; quality is gated by plan.
     */
    public function canStream(): bool
    {
        return true;
    }

    /**
     * Get max audio quality (kbps) allowed by the user's plan.
     * Free = 128, Premium/Artist/Label = 320.
     */
    public function getMaxAudioQuality(): int
    {
        return (int) $this->getPlanLimit('max_audio_quality_kbps', 128);
    }

    /**
     * Check if user can upload songs (requires artist/label plan or artist role).
     */
    public function canUpload(): bool
    {
        $plan = $this->getActivePlan();
        $uploadLimit = $this->getPlanLimit('max_uploads_per_month', 0);

        // Plan explicitly allows uploads
        if ($uploadLimit !== 0) {
            return true;
        }

        // Artists without a paid plan can still upload if their artist record allows it
        if ($this->artist && $this->artist->can_upload) {
            return true;
        }

        return false;
    }

    /**
     * Get monthly upload limit from active plan.
     * Returns the plan limit, or falls back to artist.monthly_upload_limit.
     */
    public function getMonthlyUploadLimit(): ?int
    {
        $planLimit = $this->getPlanLimit('max_uploads_per_month', null);

        // Plan-based limit takes priority
        if ($planLimit !== null && $planLimit !== 0) {
            return $planLimit === -1 ? null : $planLimit; // null = unlimited
        }

        // Fallback to artist-level limit
        return $this->artist?->monthly_upload_limit;
    }

    /**
     * Check if user has ad-free experience.
     */
    public function isAdFree(): bool
    {
        $plan = $this->getActivePlan();

        return $plan && (bool) ($plan->ad_free ?? false);
    }

    /**
     * Check if user can access offline listening.
     */
    public function canAccessOffline(): bool
    {
        $plan = $this->getActivePlan();

        return $plan && (bool) ($plan->allows_offline ?? false);
    }

    public function getRemainingDownloadsAttribute(): int
    {
        $limit = $this->getPlanLimit('max_downloads_per_day', 3);

        if ($limit === null || $limit === -1) {
            return -1; // Unlimited
        }

        $todayDownloads = Download::where('user_id', $this->id)
            ->whereDate('downloaded_at', today())
            ->count();

        return max(0, $limit - $todayDownloads);
    }

    public function getOfflinePlaylistsAttribute()
    {
        return $this->playlists()
            ->where('privacy', 'public')
            ->orWhere('user_id', $this->id)
            ->with(['songs' => function ($query) {
                $query->where('is_free', true)->where('status', 'published');
            }])
            ->get();
    }

    public function getListeningStatsAttribute(): array
    {
        $totalPlays = $this->playHistory()->where('was_completed', true)->count();
        $totalMinutes = $this->playHistory()
            ->where('was_completed', true)
            ->sum('duration_played_seconds') / 60;

        $topGenres = $this->playHistory()
            ->with('song.genres')
            ->where('was_completed', true)
            ->get()
            ->flatMap(function ($history) {
                return $history->song->genres;
            })
            ->countBy('name')
            ->sortDesc()
            ->take(5);

        return [
            'total_plays' => $totalPlays,
            'total_minutes' => round($totalMinutes),
            'top_genres' => $topGenres,
        ];
    }

    // Role and Permission Management Methods with Caching

    /**
     * Collect the user's normalized role names from the user_roles pivot.
     * Falls back to the direct 'role' column on the users table when no
     * pivot entries exist (production backward compatibility).
     */
    protected function resolveUserRoles(): array
    {
        $cacheKey = "user:{$this->id}:roles";

        return cache()->remember($cacheKey, 3600, function () {
            $pivotRoles = $this->activeRoles()
                ->pluck('name')
                ->map(fn ($name) => $this->normalizeRoleName($name))
                ->toArray();

            if (! empty($pivotRoles)) {
                return $pivotRoles;
            }

            // Fallback: use the direct 'role' column on the users table
            $directRole = $this->attributes['role'] ?? null;

            return $directRole ? [$this->normalizeRoleName($directRole)] : [];
        });
    }

    public function hasRole(string $roleName): bool
    {
        return in_array($this->normalizeRoleName($roleName), $this->resolveUserRoles());
    }

    public function hasAnyRole(array $roles): bool
    {
        $rolesNormalized = array_map(fn ($role) => $this->normalizeRoleName($role), $roles);

        return ! empty(array_intersect($rolesNormalized, $this->resolveUserRoles()));
    }

    /**
     * Normalize role name for comparison (lowercase, spaces to underscores)
     */
    protected function normalizeRoleName(string $name): string
    {
        return str_replace(' ', '_', strtolower(trim($name)));
    }

    public function hasPermission(string $permission): bool
    {
        // Check super admin
        if ($this->hasRole('super_admin')) {
            return true;
        }

        // Use cached permissions for performance
        $cacheKey = "user:{$this->id}:permissions";

        $permissions = cache()->remember($cacheKey, 3600, function () {
            return $this->loadUserPermissions();
        });

        // Check for wildcard permissions
        foreach ($permissions as $userPermission) {
            if ($userPermission === '*' || $this->matchesWildcard($permission, $userPermission)) {
                return true;
            }
        }

        return false;
    }

    protected function loadUserPermissions(): array
    {
        if ($this->hasRole('super_admin')) {
            return ['*'];
        }

        $permissions = $this->permissions ?? [];

        // Get permissions from active roles
        foreach ($this->activeRoles as $role) {
            // Get from JSON column
            $permissions = array_merge($permissions, $role->permissions ?? []);

            // Get from role_permissions table
            $rolePerms = $role->permissions()
                ->get(['permissions.slug', 'permissions.name'])
                ->map(fn ($permission) => $permission->slug ?: $permission->name)
                ->filter()
                ->values()
                ->all();
            $permissions = array_merge($permissions, $rolePerms);
        }

        return array_unique($permissions);
    }

    public function clearPermissionCache(): void
    {
        cache()->forget("user:{$this->id}:roles");
        cache()->forget("user:{$this->id}:permissions");
    }

    public function logActivity(string $event, array $data = []): void
    {
        AuditLog::logActivity($this->id, $event, $data);
    }

    private function matchesWildcard(string $permission, string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }

        $pattern = str_replace('*', '.*', preg_quote($pattern, '/'));

        return preg_match('/^'.$pattern.'$/', $permission);
    }

    public function assignRole(string $roleName, ?int $assignedBy = null, ?\DateTimeInterface $expiresAt = null): void
    {
        $role = Role::where('name', $roleName)->first();
        if (! $role) {
            throw new \InvalidArgumentException("Role '{$roleName}' not found");
        }

        $beforeRoles = $this->activeRoles()->pluck('roles.name')->values()->all();

        $this->roles()->syncWithoutDetaching([
            $role->id => [
                'assigned_at' => now(),
                'assigned_by' => $assignedBy,
            ],
        ]);

        // Clear cache
        $this->clearPermissionCache();

        $afterRoles = $this->fresh()->activeRoles()->pluck('roles.name')->values()->all();

        AuditLog::create([
            'user_id' => $assignedBy ?? $this->id,
            'action' => 'role_assigned',
            'auditable_type' => self::class,
            'auditable_id' => $this->id,
            'old_values' => [
                'roles' => $beforeRoles,
            ],
            'new_values' => [
                'user_id' => $this->id,
                'role' => $roleName,
                'roles' => $afterRoles,
                'expires_at' => $expiresAt?->format(DATE_ATOM),
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'url' => request()->fullUrl(),
        ]);
    }

    public function removeRole(string $roleName): void
    {
        $role = Role::where('name', $roleName)->first();
        if ($role) {
            $beforeRoles = $this->activeRoles()->pluck('roles.name')->values()->all();
            $this->roles()->detach($role->id);

            // Update is_artist flag if Artist role is removed
            if ($roleName === 'Artist') {
                $this->update(['is_artist' => false]);
            }

            // Clear cache
            $this->clearPermissionCache();

            $afterRoles = $this->fresh()->activeRoles()->pluck('roles.name')->values()->all();

            AuditLog::create([
                'user_id' => Auth::id() ?? $this->id,
                'action' => 'role_removed',
                'auditable_type' => self::class,
                'auditable_id' => $this->id,
                'old_values' => [
                    'roles' => $beforeRoles,
                ],
                'new_values' => [
                    'user_id' => $this->id,
                    'role' => $roleName,
                    'roles' => $afterRoles,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'url' => request()->fullUrl(),
            ]);
        }
    }

    public function getPrimaryRole(): ?Role
    {
        return Role::where('name', $this->role)->first() ??
               $this->activeRoles()->orderBy('priority', 'desc')->first();
    }

    public function addPermission(string $permission): void
    {
        $permissions = $this->permissions ?? [];
        if (! in_array($permission, $permissions)) {
            $permissions[] = $permission;
            $this->update(['permissions' => $permissions]);
        }
    }

    public function removePermission(string $permission): void
    {
        $permissions = $this->permissions ?? [];
        $permissions = array_filter($permissions, fn ($p) => $p !== $permission);
        $this->update(['permissions' => array_values($permissions)]);
    }

    public function getAllPermissions(): array
    {
        if ($this->hasRole('super_admin')) {
            return ['*'];
        }

        return $this->loadUserPermissions();
    }

    public function can($ability, $arguments = []): bool
    {
        // First check Laravel's built-in authorization
        if (parent::can($ability, $arguments)) {
            return true;
        }

        // Then check our custom permission system
        return $this->hasPermission($ability);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('Super Admin') || $this->hasRole('super_admin');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('Super Admin')
            || $this->hasRole('Admin')
            || $this->hasRole('super_admin')
            || $this->hasRole('admin');
    }

    public function isModerator(): bool
    {
        return $this->hasAnyRole(['Moderator', 'Admin', 'Super Admin', 'moderator', 'admin', 'super_admin']);
    }

    public function isModeratorOnly(): bool
    {
        return $this->hasAnyRole(['Moderator', 'moderator'])
            && ! $this->isAdmin()
            && ! $this->isSuperAdmin();
    }

    public function isArtist(): bool
    {
        return $this->hasRole('Artist') || $this->hasRole('artist') || $this->artist !== null;
    }

    public function isArtistUser(): bool
    {
        return $this->isArtist();
    }

    public function canManageUser(User $user): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->isAdmin() && ! $user->isSuperAdmin()) {
            return true;
        }

        if ($this->isModerator() && ! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return true;
        }

        return $this->id === $user->id;
    }

    public function canManageContent($content = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->isAdmin()) {
            return true;
        }

        if ($this->isModerator()) {
            return true;
        }

        // Check if user owns the content
        if ($content && method_exists($content, 'user_id')) {
            return $content->user_id === $this->id;
        }

        return false;
    }

    // Security methods
    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
        // Note: API token revocation would require Laravel Sanctum
    }

    public function ban(): void
    {
        $this->deactivate();
        // Additional ban logic can be added here
    }

    public function isActive(): bool
    {
        return $this->is_active ?? true; // Default to true if null
    }

    // Artist onboarding and verification methods
    public function isPendingVerification(): bool
    {
        return $this->status === 'pending';
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isPhoneVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function canAccessArtistDashboard(): bool
    {
        return $this->isVerified() && $this->hasAnyRole(['artist', 'admin', 'super_admin']);
    }

    public function canAccessAdminPanel(): bool
    {
        return $this->hasAnyRole(['super_admin', 'admin', 'moderator', 'finance']);
    }

    public function approve(User $approver, ?string $notes = null): void
    {
        $this->update([
            'status' => 'verified',
            'verified_by' => $approver->id,
            'verified_at' => now(),
            'rejection_reason' => null,
        ]);

        // Assign artist role if not already assigned
        if (! $this->hasRole('artist')) {
            $this->assignRole('artist', $approver->id);
        }
    }

    public function reject(User $rejector, string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'verified_by' => $rejector->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function suspend(User $suspender, string $reason): void
    {
        $this->update([
            'status' => 'suspended',
            'verified_by' => $suspender->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    // Phone verification methods
    public function generatePhoneVerificationCode(): string
    {
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->update([
            'phone_verification_code' => $code,
            'phone_verification_expires_at' => now()->addMinutes(10),
        ]);

        return $code;
    }

    public function verifyPhone(string $code): bool
    {
        if ($this->phone_verification_code === $code &&
            $this->phone_verification_expires_at &&
            $this->phone_verification_expires_at->isFuture()) {

            $this->update([
                'phone_verified_at' => now(),
                'phone_verification_code' => null,
                'phone_verification_expires_at' => null,
            ]);

            return true;
        }

        return false;
    }

    public function clearPhoneVerification(): void
    {
        $this->update([
            'phone_verification_code' => null,
            'phone_verification_expires_at' => null,
        ]);
    }

    // 2FA methods for admins
    public function enableTwoFactor(): void
    {
        $this->update(['two_factor_enabled' => true]);
        $this->syncSecurityProfile();
    }

    public function disableTwoFactor(): void
    {
        $this->update([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
        $this->syncSecurityProfile();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_enabled && $this->two_factor_confirmed_at;
    }

    // Login tracking methods
    public function updateLastLogin(string $portal = 'web'): void
    {
        $updates = ['last_login_at' => now()];

        if ($portal === 'admin') {
            $updates['last_admin_login_at'] = now();
        } elseif ($portal === 'artist') {
            $updates['last_artist_login_at'] = now();
        }

        $this->update($updates);
    }

    // Status display methods
    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'pending' => '⏳ Pending Verification',
            'verified' => '✅ Verified',
            'rejected' => '❌ Rejected',
            'suspended' => '⏸️ Suspended',
            default => '❓ Unknown'
        };
    }

    public function getVerificationStatusAttribute(): string
    {
        if ($this->isVerified()) {
            return 'Verified Artist';
        } elseif ($this->isRejected()) {
            return 'Application Rejected';
        } elseif ($this->isSuspended()) {
            return 'Account Suspended';
        } else {
            return 'Pending Verification';
        }
    }

    // Artist-specific helper methods
    public function getDisplayNameAttribute(): string
    {
        $profileDisplayName = $this->profileValue('display_name', $this->attributes['display_name'] ?? null);
        if ($profileDisplayName) {
            return $profileDisplayName;
        }

        // If user has artist profile, use artist's stage name
        if ($this->artist) {
            return $this->artist->stage_name;
        }

        $firstName = $this->profileValue('first_name', $this->attributes['first_name'] ?? '');
        $lastName = $this->profileValue('last_name', $this->attributes['last_name'] ?? '');

        return trim($firstName.' '.$lastName) ?: 'User';
    }

    public function canRequestPayout(): bool
    {
        return $this->isVerified() &&
               $this->hasRole('artist') &&
               $this->isPhoneVerified();
    }

    // Admin portal specific methods
    public function isFinanceAdmin(): bool
    {
        return $this->hasAnyRole(['finance', 'admin', 'super_admin']);
    }

    public function canManagePayouts(): bool
    {
        return $this->isFinanceAdmin();
    }

    public function canVerifyArtists(): bool
    {
        return $this->hasAnyRole(['moderator', 'admin', 'super_admin']);
    }

    public function canViewSystemAnalytics(): bool
    {
        return $this->hasAnyRole(['admin', 'super_admin']);
    }

    // SACCO Module Integration Methods
    public function isSaccoMember(): bool
    {
        return $this->saccoMember !== null && $this->saccoMember->status === 'active';
    }

    public function canJoinSacco(): bool
    {
        // Artists who are verified can join SACCO
        return $this->isVerified()
            && $this->hasRole('artist')
            && ! $this->isSaccoMember();
    }

    public function getSaccoAccountsAttribute()
    {
        return $this->saccoMember?->accounts ?? collect();
    }

    public function getTotalSaccoBalanceAttribute(): float
    {
        return $this->saccoMember?->total_balance ?? 0;
    }

    public function hasActiveSaccoLoans(): bool
    {
        return $this->saccoMember?->loans()
            ->whereIn('status', ['active', 'disbursed'])
            ->exists() ?? false;
    }

    public function getSaccoEligibilityAttribute(): array
    {
        $member = $this->saccoMember;

        if (! $member || $member->status !== 'active') {
            return [
                'eligible' => false,
                'reason' => 'Not an active SACCO member',
            ];
        }

        return [
            'eligible' => true,
            'max_loan_amount' => max(
                $member->total_savings * 3,
                $member->total_shares * 4
            ),
            'current_loans' => $member->active_loans_count,
            'max_loans' => 3,
        ];
    }

    // =========================================================================
    // NEW: Social Authentication & KYC Helper Methods
    // =========================================================================

    /**
     * Check if user has verified KYC document of specific type
     */
    public function hasVerifiedKYC(string $type): bool
    {
        return $this->kycDocuments()
            ->where('document_type', $type)
            ->where('status', KYCDocument::STATUS_VERIFIED)
            ->exists();
    }

    /**
     * Check if user has all required KYC documents verified
     */
    public function hasAllRequiredKYCDocuments(): bool
    {
        $requiredTypes = [
            KYCDocument::TYPE_NATIONAL_ID_FRONT,
            KYCDocument::TYPE_NATIONAL_ID_BACK,
            KYCDocument::TYPE_SELFIE_WITH_ID,
        ];

        foreach ($requiredTypes as $type) {
            if (! $this->hasVerifiedKYC($type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if user can apply for artist status
     */
    public function canApplyForArtistStatus(): bool
    {
        // Already has artist profile
        if ($this->artist) {
            return false;
        }

        // Already has pending application
        if ($this->artist_application_submitted_at) {
            return false;
        }

        // Profile completion must be at least 50%
        if ($this->profile_completion_percentage < 50) {
            return false;
        }

        return true;
    }

    /**
     * Check if user authenticated via social provider
     */
    public function isSocialUser(): bool
    {
        return ! empty($this->provider) && ! empty($this->provider_id);
    }

    /**
     * Get social provider display name
     */
    public function getSocialProviderName(): ?string
    {
        return $this->provider ? ucfirst($this->provider) : null;
    }

    /**
     * Check if user requires phone verification
     * (Required for artists or security reasons)
     */
    public function requiresPhoneVerification(): bool
    {
        // Artists must verify phone
        if ($this->hasRole('artist') && ! $this->isPhoneVerified()) {
            return true;
        }

        // Check if 2FA is enabled and phone not verified
        if ($this->two_factor_enabled && ! $this->isPhoneVerified()) {
            return true;
        }

        return false;
    }

    // REMOVED DUPLICATE requiresPhoneVerification() METHOD - ALREADY DEFINED ABOVE

    /**
     * Calculate profile completion percentage
     */
    public function calculateProfileCompletion(): int
    {
        $totalSteps = 10;
        $completed = 0;

        if ($this->name) {
            $completed++;
        }
        if ($this->email) {
            $completed++;
        }
        if ($this->avatar) {
            $completed++;
        }
        if ($this->bio) {
            $completed++;
        }
        if ($this->phone) {
            $completed++;
        }
        if ($this->isPhoneVerified()) {
            $completed++;
        }
        if ($this->country) {
            $completed++;
        }
        if ($this->city) {
            $completed++;
        }
        if ($this->date_of_birth) {
            $completed++;
        }
        if ($this->isSocialUser() || $this->password) {
            $completed++;
        }

        return (int) (($completed / $totalSteps) * 100);
    }

    /**
     * Update profile completion tracking
     */
    public function updateProfileCompletion(): void
    {
        $percentage = $this->calculateProfileCompletion();
        $steps = [];

        if ($this->email && $this->email_verified_at) {
            $steps[] = 'email_verified';
        }
        if ($this->phone && $this->phone_verified_at) {
            $steps[] = 'phone_verified';
        }
        if ($this->avatar) {
            $steps[] = 'avatar_uploaded';
        }
        if ($this->bio) {
            $steps[] = 'bio_added';
        }
        if ($this->country && $this->city) {
            $steps[] = 'location_added';
        }
        if ($this->date_of_birth) {
            $steps[] = 'dob_added';
        }

        $this->update([
            'profile_completion_percentage' => $percentage,
            'profile_steps_completed' => $steps,
        ]);

        $this->profile()->updateOrCreate(
            ['user_id' => $this->id],
            [
                'profile_completion_percentage' => $percentage,
                'profile_steps_completed' => $steps,
            ]
        );
    }

    public function syncSecurityProfile(): void
    {
        $this->securityProfile()->updateOrCreate(
            ['user_id' => $this->id],
            UserSecurityProfile::payloadFromUser($this)
        );
    }

    protected function normalizeRecoveryCodesForStorage(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return json_encode(array_values($value));
        }

        if (is_string($value)) {
            json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $value : json_encode([$value]);
        }

        return json_encode([$value]);
    }

    public function syncArtistApplicationState(array $attributes): void
    {
        $legacyUserUpdates = [];
        foreach ([
            'full_name',
            'nin_number',
            'phone',
            'mobile_money_number',
            'mobile_money_provider',
            'application_status',
            'rejection_reason',
        ] as $key) {
            if (array_key_exists($key, $attributes)) {
                $legacyUserUpdates[$key] = $attributes[$key];
            }
        }

        if (! empty($legacyUserUpdates)) {
            $this->update($legacyUserUpdates);
        }

        $this->profile()->updateOrCreate(
            ['user_id' => $this->id],
            array_filter([
                'display_name' => $attributes['stage_name'] ?? null,
                'bio' => $attributes['bio'] ?? null,
                'country' => $attributes['country'] ?? null,
                'city' => $attributes['city'] ?? null,
                'instagram_url' => $attributes['social_links']['instagram'] ?? null,
                'twitter_url' => $attributes['social_links']['twitter'] ?? null,
                'facebook_url' => $attributes['social_links']['facebook'] ?? null,
                'youtube_url' => $attributes['social_links']['youtube'] ?? null,
                'tiktok_url' => $attributes['social_links']['tiktok'] ?? null,
            ], fn ($value) => $value !== null)
        );

        $verificationDocuments = $attributes['verification_documents'] ?? [];

        if (! static::hasArtistProfilesTable()) {
            return;
        }

        $this->artistProfile()->updateOrCreate(
            ['user_id' => $this->id],
            [
                'stage_name' => $attributes['stage_name'] ?? $this->artistProfile?->stage_name,
                'real_name' => $attributes['full_name'] ?? $this->artistProfile?->real_name,
                'nin_number' => $attributes['nin_number'] ?? $this->artistProfile?->nin_number,
                'verification_status' => $attributes['verification_status'] ?? 'pending',
                'verification_documents' => $verificationDocuments,
                'bio' => $attributes['bio'] ?? $this->artistProfile?->bio,
                'website' => $attributes['website_url'] ?? $this->artistProfile?->website,
                'social_links' => $attributes['social_links'] ?? $this->artistProfile?->social_links,
                'genres' => $attributes['genres'] ?? $this->artistProfile?->genres,
                'career_stage' => $attributes['career_stage'] ?? $this->artistProfile?->career_stage,
                'mobile_money_provider' => $attributes['mobile_money_provider'] ?? $this->artistProfile?->mobile_money_provider,
                'mobile_money_number' => $attributes['mobile_money_number'] ?? $this->artistProfile?->mobile_money_number,
                'bank_name' => $attributes['bank_name'] ?? $this->artistProfile?->bank_name,
                'bank_account' => $attributes['bank_account'] ?? $this->artistProfile?->bank_account,
                'payout_method' => $attributes['artist_profile_payout_method'] ?? $this->artistProfile?->payout_method ?? 'mobile_money',
                'profile_completed' => (bool) ($attributes['profile_completed'] ?? true),
                'is_active' => true,
            ]
        );
    }

    public function syncArtistReviewState(Artist $artist, array $attributes): void
    {
        $applicationStatus = $attributes['application_status'] ?? null;
        $verifiedAt = $attributes['verified_at'] ?? null;
        $verifiedBy = $attributes['verified_by'] ?? null;
        $rejectionReason = $attributes['rejection_reason'] ?? null;
        $isArtist = (bool) ($attributes['is_artist'] ?? false);

        $this->update(array_filter([
            'application_status' => $applicationStatus,
            'verified_at' => $verifiedAt,
            'verified_by' => $verifiedBy,
            'rejection_reason' => $rejectionReason,
            'is_artist' => $isArtist,
            'phone_verified_at' => $attributes['phone_verified_at'] ?? null,
        ], fn ($value) => $value !== null));

        if (! empty($attributes['email_verified_at']) && ! $this->email_verified_at) {
            $this->forceFill([
                'email_verified_at' => $attributes['email_verified_at'],
            ])->save();
        }

        if (! static::hasArtistProfilesTable()) {
            return;
        }

        $this->artistProfile()->updateOrCreate(
            ['user_id' => $this->id],
            [
                'artist_id' => $artist->id,
                'stage_name' => $artist->stage_name,
                'real_name' => $this->full_name ?? $this->artistProfile?->real_name,
                'nin_number' => $this->nin_number ?? $this->artistProfile?->nin_number,
                'verification_status' => $attributes['verification_status'] ?? $this->artistProfile?->verification_status ?? 'pending',
                'verification_documents' => $this->artistProfile?->verification_documents ?? [],
                'verified_at' => $verifiedAt,
                'bio' => $artist->bio ?? $this->artistProfile?->bio,
                'website' => $artist->website_url ?? $this->artistProfile?->website,
                'social_links' => $artist->social_links ?? $this->artistProfile?->social_links,
                'genres' => $attributes['genres'] ?? $this->artistProfile?->genres,
                'mobile_money_provider' => $this->mobile_money_provider ?? $this->artistProfile?->mobile_money_provider,
                'mobile_money_number' => $this->mobile_money_number ?? $this->artistProfile?->mobile_money_number,
                'bank_name' => $this->artistProfile?->bank_name,
                'bank_account' => $this->artistProfile?->bank_account,
                'payout_method' => $this->artistProfile?->payout_method ?? 'mobile_money',
                'profile_completed' => true,
                'is_active' => $attributes['artist_profile_active'] ?? true,
            ]
        );
    }

    public static function hasArtistProfilesTable(): bool
    {
        if (static::$hasArtistProfilesTable === null) {
            static::$hasArtistProfilesTable = Schema::hasTable('artist_profiles');
        }

        return static::$hasArtistProfilesTable;
    }

    protected function profileValue(string $key, mixed $fallback = null): mixed
    {
        if ($this->relationLoaded('profile') && $this->profile) {
            $value = $this->profile->getAttribute($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    public function hasVerifiedEmail(): bool
    {
        $directRole = $this->attributes['role'] ?? $this->getAttribute('role');

        if ($directRole && in_array($this->normalizeRoleName((string) $directRole), ['admin', 'super_admin'], true)) {
            return true;
        }

        if ($this->hasAnyRole(['admin', 'super_admin'])) {
            return true;
        }

        return ! is_null($this->email_verified_at);
    }

    protected function referralValue(string $key, mixed $fallback = null): mixed
    {
        if ($this->relationLoaded('referralProfile') && $this->referralProfile) {
            $value = $this->referralProfile->getAttribute($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    protected function settingsValue(string $key, mixed $fallback = null): mixed
    {
        $settings = $this->settings;

        if ($settings instanceof UserSetting) {
            $value = $settings->getAttribute($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    /**
     * Send the email verification notification.
     * Uses custom branded notification.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Notifications\VerifyEmailNotification);
    }
}
