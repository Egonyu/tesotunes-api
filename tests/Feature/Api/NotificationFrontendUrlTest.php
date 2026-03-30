<?php

namespace Tests\Feature\Api;

use App\Models\Song;
use App\Models\User;
use App\Notifications\AdminSongPendingNotification;
use App\Notifications\ArtistApplicationNotification;
use App\Notifications\WelcomeNotification;
use Tests\TestCase;

class NotificationFrontendUrlTest extends TestCase
{
    public function test_mail_notifications_use_frontend_routes_instead_of_api_routes(): void
    {
        config([
            'app.url' => 'https://api.tesotunes.com',
            'app.frontend_url' => '',
        ]);

        $user = new User([
            'display_name' => 'Mail Link User',
            'email' => 'mail-link@example.com',
        ]);

        $admin = new User([
            'display_name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        $song = new Song([
            'title' => 'Frontend Song',
        ]);
        $song->id = 42;

        $artistApplicationMail = (new ArtistApplicationNotification(ArtistApplicationNotification::APPROVED))
            ->toMail($user);
        $welcomeMail = (new WelcomeNotification)->toMail($user);
        $adminSongMail = (new AdminSongPendingNotification($song, $user))->toMail($admin);

        $this->assertSame('https://tesotunes.com/artist/dashboard', $artistApplicationMail->actionUrl);
        $this->assertSame('https://tesotunes.com/discover', $welcomeMail->actionUrl);
        $this->assertSame('https://tesotunes.com/admin/songs/42', $adminSongMail->actionUrl);

        $this->assertStringNotContainsString('api.tesotunes.com', $artistApplicationMail->actionUrl);
        $this->assertStringNotContainsString('api.tesotunes.com', $welcomeMail->actionUrl);
        $this->assertStringNotContainsString('api.tesotunes.com', $adminSongMail->actionUrl);
    }
}
