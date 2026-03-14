<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Services\Auth\SocialAuthService;
use App\Services\ProfileCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialAuthNotificationStabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_signup_creates_custom_welcome_notification(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'user'],
            [
                'display_name' => 'User',
                'description' => 'Standard user role',
                'is_active' => true,
                'priority' => 1,
            ]
        );

        $profileService = $this->createMock(ProfileCompletionService::class);

        $service = new class($profileService) extends SocialAuthService
        {
            public function createFromSocialFake(object $socialUser, string $provider)
            {
                return $this->createUserFromSocial($socialUser, $provider);
            }
        };

        $socialUser = new class
        {
            public string $token = 'social-token';

            public ?string $refreshToken = 'refresh-token';

            public function getName(): string
            {
                return 'Social Signup User';
            }

            public function getEmail(): string
            {
                return 'social@example.com';
            }

            public function getAvatar(): string
            {
                return 'https://example.com/avatar.jpg';
            }

            public function getId(): string
            {
                return 'social-provider-id';
            }
        };

        $user = $service->createFromSocialFake($socialUser, 'google');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'welcome',
            'category' => 'auth',
            'title' => 'Welcome to LineOne Music!',
        ]);

        $this->assertDatabaseHas('user_settings', [
            'user_id' => $user->id,
        ]);
    }
}
