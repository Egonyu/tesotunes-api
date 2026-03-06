<?php

namespace App\Providers;

use App\Models\Album;
use App\Models\ArtistRevenue;
use App\Models\AwardVote;
use App\Models\Event as EventModel;
use App\Models\Like;
use App\Models\Payment;
use App\Models\Playlist;
use App\Models\Setting;
// use App\Models\ArtistPayout; // Not yet implemented
use App\Models\Song;
use App\Observers\AlbumObserver;
use App\Observers\ArtistPayoutObserver;
use App\Observers\ArtistRevenueObserver;
use App\Observers\AwardVoteObserver;
use App\Observers\EventObserver;
use App\Observers\LikeObserver;
// use App\Models\UserFollow; // Not yet implemented
// use App\Models\Comment; // Not yet implemented
// use App\Models\Share; // Not yet implemented
// use App\Models\User; // Not yet implemented
// use App\Models\Artist; // Not yet implemented
// use App\Models\Modules\Forum\ForumTopic; // Not yet implemented
// use App\Models\Modules\Forum\ForumReply; // Not yet implemented
// use App\Models\Modules\Forum\Poll; // Not yet implemented
use App\Observers\PaymentObserver;
use App\Observers\PlaylistObserver;
use App\Observers\SongObserver;
use App\Services\Payment\ZengaPayService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\RateLimiter;
// use App\Observers\UserFollowObserver; // Not yet implemented
// use App\Observers\CommentObserver; // Not yet implemented
// use App\Observers\ShareObserver; // Not yet implemented
// use App\Observers\UserObserver; // Not yet implemented
// use App\Observers\ArtistObserver; // Not yet implemented
// use App\Observers\ForumTopicObserver; // Not yet implemented
// use App\Observers\ForumReplyObserver; // Not yet implemented
// use App\Observers\PollObserver; // Not yet implemented
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register ZengaPay Service as singleton
        $this->app->singleton(ZengaPayService::class, function ($app) {
            return new ZengaPayService;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Set global Eloquent pagination defaults
        \Illuminate\Database\Eloquent\Model::$snakeAttributes = true;

        // Register observers for financial models
        Payment::observe(PaymentObserver::class);
        // ArtistPayout::observe(ArtistPayoutObserver::class); // Not yet implemented
        ArtistRevenue::observe(ArtistRevenueObserver::class);

        // Register observer for store reviews
        // \App\Modules\Store\Models\Review::observe(\App\Observers\ReviewObserver::class); // Not yet implemented

        // Register observers for timeline activities (Timeline Phase 2)
        // User::observe(UserObserver::class); // Not yet implemented
        // Artist::observe(ArtistObserver::class); // Not yet implemented

        // Register observers for activity tracking (Phase 4: Activity Tracking)
        Song::observe(SongObserver::class);
        Album::observe(AlbumObserver::class);
        EventModel::observe(EventObserver::class);
        Playlist::observe(PlaylistObserver::class);
        Like::observe(LikeObserver::class);
        // UserFollow::observe(UserFollowObserver::class); // Not yet implemented
        // Comment::observe(CommentObserver::class); // Not yet implemented
        // Share::observe(ShareObserver::class); // Not yet implemented
        AwardVote::observe(AwardVoteObserver::class);

        // Register observers for Awards module (Phase 3: Feed Infrastructure)
        // \App\Models\Award::observe(\App\Observers\AwardObserver::class); // Not yet implemented
        // \App\Models\AwardNomination::observe(\App\Observers\AwardNominationObserver::class); // Not yet implemented
        // \App\Models\AwardWinner::observe(\App\Observers\AwardWinnerObserver::class); // Not yet implemented

        // Register observers for Store module (Phase 3: Feed Infrastructure)
        // \App\Modules\Store\Models\Product::observe(\App\Observers\Store\ProductObserver::class); // Not yet implemented
        // \App\Modules\Store\Models\Store::observe(\App\Observers\Store\StoreObserver::class); // Not yet implemented
        // \App\Models\Order::observe(\App\Observers\Store\OrderObserver::class); // Not yet implemented

        // Register observers for SACCO module (Phase 3: Feed Infrastructure)
        // \App\Models\Sacco\SaccoDividend::observe(\App\Observers\Sacco\SaccoDividendObserver::class); // Not yet implemented
        // \App\Models\Sacco\SaccoMember::observe(\App\Observers\Sacco\SaccoMemberObserver::class); // Not yet implemented
        // \App\Models\Sacco\SaccoMemberDividend::observe(\App\Observers\Sacco\SaccoMemberDividendObserver::class); // Not yet implemented

        // Register observers for Ojokotau module (Phase 3: Feed Infrastructure)
        // \App\Modules\Ojokotau\Models\Campaign::observe(\App\Observers\Ojokotau\CampaignObserver::class); // Not yet implemented
        // \App\Modules\Ojokotau\Models\CampaignEndorsement::observe(\App\Observers\Ojokotau\CampaignEndorsementObserver::class); // Not yet implemented
        // \App\Modules\Ojokotau\Models\CampaignPledge::observe(\App\Observers\Ojokotau\CampaignPledgeObserver::class); // Not yet implemented

        // Register observers for Loyalty module
        \App\Models\Loyalty\LoyaltyCard::observe(\App\Observers\Loyalty\LoyaltyCardObserver::class);
        \App\Models\Loyalty\LoyaltyCardMember::observe(\App\Observers\Loyalty\LoyaltyCardMemberObserver::class);
        \App\Models\Loyalty\LoyaltyReward::observe(\App\Observers\Loyalty\LoyaltyRewardObserver::class);

        // Register event listeners for loyalty points
        EventFacade::listen(
            \App\Events\AttendeeCheckedIn::class,
            [\App\Listeners\AwardEventLoyaltyPoints::class, 'handleAttendeeCheckedIn']
        );
        EventFacade::listen(
            \App\Events\TicketPurchased::class,
            [\App\Listeners\AwardEventLoyaltyPoints::class, 'handleTicketPurchased']
        );
        EventFacade::listen(
            \App\Events\OrderPaid::class,
            [\App\Listeners\AwardStoreLoyaltyPoints::class, 'handleOrderPaid']
        );

        // Register event listeners for notifications (content events)
        EventFacade::listen(
            \App\Events\Podcast\NewEpisodePublished::class,
            [\App\Listeners\SendEventNotifications::class, 'handleNewEpisodePublished']
        );
        EventFacade::listen(
            \App\Events\Podcast\NewPodcastPublished::class,
            [\App\Listeners\SendEventNotifications::class, 'handleNewPodcastPublished']
        );
        EventFacade::listen(
            \App\Events\TicketPurchased::class,
            [\App\Listeners\SendEventNotifications::class, 'handleTicketPurchased']
        );

        // Register event listeners for audit logging (Auth events)
        EventFacade::listen(
            \Illuminate\Auth\Events\Login::class,
            [\App\Listeners\AuditLoggingListener::class, 'handleLogin']
        );
        EventFacade::listen(
            \Illuminate\Auth\Events\Logout::class,
            [\App\Listeners\AuditLoggingListener::class, 'handleLogout']
        );
        EventFacade::listen(
            \Illuminate\Auth\Events\Failed::class,
            [\App\Listeners\AuditLoggingListener::class, 'handleFailed']
        );
        EventFacade::listen(
            \Illuminate\Auth\Events\Registered::class,
            [\App\Listeners\AuditLoggingListener::class, 'handleRegistered']
        );
        EventFacade::listen(
            \Illuminate\Auth\Events\Verified::class,
            [\App\Listeners\AuditLoggingListener::class, 'handleVerified']
        );
        EventFacade::listen(
            \Illuminate\Auth\Events\PasswordReset::class,
            [\App\Listeners\AuditLoggingListener::class, 'handlePasswordReset']
        );

        // Register observers for Forum & Polls module (Phase 4: Dashboard Integration)
        // ForumTopic::observe(ForumTopicObserver::class); // Not yet implemented
        // ForumReply::observe(ForumReplyObserver::class); // Not yet implemented
        // Poll::observe(PollObserver::class); // Not yet implemented

        // Configure password validation based on settings
        $this->configurePasswordValidation();

        // Configure rate limiters for authentication and API
        $this->configureRateLimiting();

        // Custom Blade directive for checking if features are enabled
        Blade::if('featureEnabled', function ($feature) {
            return Setting::get($feature, true);
        });

        // Custom Blade directive for checking specific common features
        Blade::if('phoneVerificationEnabled', function () {
            return Setting::get('phone_verification_enabled', true);
        });

        Blade::if('awardsEnabled', function () {
            return Setting::get('awards_system_enabled', true);
        });

        Blade::if('eventsEnabled', function () {
            return Setting::get('events_module_enabled', true);
        });

        Blade::if('ticketsEnabled', function () {
            return Setting::get('ticket_sales_enabled', true);
        });

        Blade::if('artistRegistrationEnabled', function () {
            return Setting::get('artist_registration_enabled', true);
        });

        Blade::if('musicStreamingEnabled', function () {
            return Setting::get('music_streaming_enabled', true);
        });

        Blade::if('musicDownloadsEnabled', function () {
            return Setting::get('music_downloads_enabled', true);
        });

        Blade::if('socialFeaturesEnabled', function () {
            return Setting::get('social_features_enabled', true);
        });

        // Environment checking directives
        Blade::if('production', function () {
            return \App\Services\EnvironmentService::isProduction();
        });

        Blade::if('development', function () {
            return \App\Services\EnvironmentService::isDevelopment();
        });

        Blade::directive('env', function ($environment) {
            return "<?php if(\App\Services\EnvironmentService::getEnvironment() === {$environment}): ?>";
        });

        Blade::directive('endenv', function () {
            return '<?php endif; ?>';
        });

        Blade::if('promotionsEnabled', function () {
            return Setting::get('community_promotions_enabled', true);
        });

        Blade::if('creditsEnabled', function () {
            return Setting::get('credit_system_enabled', true);
        });

        Blade::if('subscriptionsEnabled', function () {
            return Setting::get('subscription_system_enabled', true);
        });

        Blade::if('playlistsEnabled', function () {
            return Setting::get('playlist_creation_enabled', true);
        });

        // Authentication settings Blade directives
        Blade::if('emailLoginEnabled', function () {
            return Setting::get('auth_email_login_enabled', true);
        });

        Blade::if('phoneLoginEnabled', function () {
            return Setting::get('auth_phone_login_enabled', true);
        });

        Blade::if('socialLoginEnabled', function ($provider) {
            return Setting::get("auth_{$provider}_login_enabled", false);
        });

        // Custom Blade directive for safe date formatting
        Blade::directive('diffForHumans', function ($expression) {
            return "<?php echo is_string($expression) ? \\Carbon\\Carbon::parse($expression)->diffForHumans() : (is_object($expression) && method_exists($expression, 'diffForHumans') ? $expression->diffForHumans() : ''); ?>";
        });
    }

    /**
     * Configure password validation rules based on admin settings
     */
    protected function configurePasswordValidation(): void
    {
        \Illuminate\Validation\Rules\Password::defaults(function () {
            $minLength = Setting::get('auth_password_min_length', 8);
            $requireSpecial = Setting::get('auth_password_require_special_char', true);
            $requireNumber = Setting::get('auth_password_require_number', true);
            $requireUpper = Setting::get('auth_password_require_uppercase', true);

            $rule = \Illuminate\Validation\Rules\Password::min($minLength);

            if ($requireSpecial) {
                $rule->symbols();
            }

            if ($requireNumber) {
                $rule->numbers();
            }

            if ($requireUpper) {
                $rule->mixedCase();
            }

            return $rule;
        });
    }

    /**
     * Configure rate limiting for the application
     * Uganda-friendly limits to account for unreliable internet
     */
    protected function configureRateLimiting(): void
    {
        // Login attempts (prevent brute force) - now uses settings
        RateLimiter::for('login', function (Request $request) {
            $maxAttempts = Setting::get('auth_max_login_attempts', 5);

            return Limit::perMinute($maxAttempts)->by($request->ip());
        });

        // API calls (generous for unreliable internet)
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(100)->by($request->user()->id)
                : Limit::perMinute(20)->by($request->ip());
        });

        // Streaming (premium vs free)
        RateLimiter::for('streaming', function (Request $request) {
            if ($request->user()?->hasActiveSubscription()) {
                return Limit::none();
            }

            return Limit::perMinute(30)->by($request->user()?->id ?? $request->ip());
        });

        // File uploads (prevent abuse)
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?? $request->ip());
        });

        // Registration (prevent spam)
        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(3)->by($request->ip());
        });
    }
}
