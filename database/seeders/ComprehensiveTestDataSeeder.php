<?php

namespace Database\Seeders;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Event;
use App\Models\FeedItem;
use App\Models\Genre;
use App\Models\Payment;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\Song;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ComprehensiveTestDataSeeder extends Seeder
{
    /**
     * Comprehensive seeder for testing all platform features.
     * Idempotent — safe to run multiple times.
     */
    public function run(): void
    {
        $this->command->info('--- Comprehensive Test Data Seeder ---');

        // Ensure genres & moods exist
        $this->call([GenreSeeder::class]);
        if (Schema::hasTable('moods')) {
            try {
                $this->call([MoodSeeder::class]);
            } catch (\Exception $e) {
                $this->command->warn('MoodSeeder skipped: ' . Str::limit($e->getMessage(), 80));
            }
        }

        $genres = Genre::all();
        if ($genres->isEmpty()) {
            $this->command->error('No genres found — cannot continue.');
            return;
        }

        // ─── 1. Users (10 extra listeners + 5 artist users) ───
        $this->command->info('Seeding users...');
        $listenerUsers = $this->seedListeners(10);
        $artistUsers   = $this->seedArtistUsers(5);

        // ─── 2. Artists (5 profiles) ───
        $this->command->info('Seeding artists...');
        $artists = $this->seedArtists($artistUsers, $genres);

        // ─── 3. Albums (2-3 per artist) ───
        $this->command->info('Seeding albums...');
        $albums = $this->seedAlbums($artists, $genres);

        // ─── 4. Songs (4-8 per artist, spread across albums) ───
        $this->command->info('Seeding songs...');
        $songs = $this->seedSongs($artists, $albums, $genres);

        // ─── 5. Playlists (3 curated + 5 user-generated) ───
        $this->command->info('Seeding playlists...');
        $this->seedPlaylists($listenerUsers, $songs);

        // ─── 6. Events (6 upcoming + 2 past) ───
        $this->command->info('Seeding events...');
        $this->seedEvents($artistUsers);

        // ─── 7. Posts (social content) ───
        $this->command->info('Seeding posts...');
        $this->seedPosts($listenerUsers, $artistUsers, $songs);

        // ─── 8. Feed Items (pre-built feed) ───
        $this->command->info('Seeding feed items...');
        $this->seedFeedItems($artists, $songs, $albums);

        // ─── 9. Payments (sample transactions) ───
        $this->command->info('Seeding payments...');
        $this->seedPayments($listenerUsers, $songs);

        $this->command->info('✅ Comprehensive test data seeded successfully.');
    }

    // ─────────────────────────────────────────────────────────
    //  Listener Users
    // ─────────────────────────────────────────────────────────
    private function seedListeners(int $count): array
    {
        $users = [];
        $names = [
            ['first' => 'Grace',   'last' => 'Nakamya'],
            ['first' => 'Joseph',  'last' => 'Okello'],
            ['first' => 'Maria',   'last' => 'Namukasa'],
            ['first' => 'Brian',   'last' => 'Ssempijja'],
            ['first' => 'Sarah',   'last' => 'Achieng'],
            ['first' => 'David',   'last' => 'Mugisha'],
            ['first' => 'Esther',  'last' => 'Nabwire'],
            ['first' => 'Peter',   'last' => 'Odongo'],
            ['first' => 'Ruth',    'last' => 'Atim'],
            ['first' => 'Moses',   'last' => 'Waiswa'],
        ];

        foreach (array_slice($names, 0, $count) as $i => $name) {
            $email = strtolower($name['first']) . '.' . strtolower($name['last']) . '@tesotunes-test.com';
            $users[] = User::firstOrCreate(
                ['email' => $email],
                [
                    'uuid'              => Str::uuid()->toString(),
                    'name'              => $name['first'] . ' ' . $name['last'],
                    'username'          => strtolower($name['first']) . strtolower($name['last']),
                    'first_name'        => $name['first'],
                    'last_name'         => $name['last'],
                    'display_name'      => $name['first'] . ' ' . $name['last'],
                    'password'          => Hash::make('password'),
                    'email_verified_at' => now(),
                    'is_active'         => true,
                    'status'            => 'active',
                    'country'           => 'Uganda',
                    'city'              => fake()->randomElement(['Kampala', 'Entebbe', 'Jinja', 'Mbarara', 'Gulu']),
                    'timezone'          => 'Africa/Kampala',
                    'language'          => 'en',
                    'credits'           => fake()->numberBetween(50, 500),
                ]
            );
        }

        return $users;
    }

    // ─────────────────────────────────────────────────────────
    //  Artist Users
    // ─────────────────────────────────────────────────────────
    private function seedArtistUsers(int $count): array
    {
        $users = [];
        $artistPeople = [
            ['first' => 'Eddy',    'last' => 'Kenzo',    'stage' => 'Eddy Kenzo'],
            ['first' => 'Sheebah', 'last' => 'Karungi',  'stage' => 'Sheebah'],
            ['first' => 'Bebe',    'last' => 'Cool',     'stage' => 'Bebe Cool'],
            ['first' => 'Cindy',   'last' => 'Sanyu',    'stage' => 'Cindy Sanyu'],
            ['first' => 'John',    'last' => 'Blaq',     'stage' => 'John Blaq'],
        ];

        foreach (array_slice($artistPeople, 0, $count) as $person) {
            $email = strtolower(str_replace(' ', '', $person['stage'])) . '@tesotunes-test.com';
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'uuid'              => Str::uuid()->toString(),
                    'name'              => $person['first'] . ' ' . $person['last'],
                    'username'          => strtolower(str_replace(' ', '', $person['stage'])),
                    'first_name'        => $person['first'],
                    'last_name'         => $person['last'],
                    'display_name'      => $person['stage'],
                    'stage_name'        => $person['stage'],
                    'password'          => Hash::make('password'),
                    'email_verified_at' => now(),
                    'is_active'         => true,
                    'is_artist'         => true,
                    'status'            => 'active',
                    'country'           => 'Uganda',
                    'city'              => 'Kampala',
                    'timezone'          => 'Africa/Kampala',
                    'language'          => 'en',
                    'credits'           => fake()->numberBetween(200, 2000),
                ]
            );
            $user->_stage = $person['stage'];
            $users[] = $user;
        }

        return $users;
    }

    // ─────────────────────────────────────────────────────────
    //  Artists
    // ─────────────────────────────────────────────────────────
    private function seedArtists(array $artistUsers, $genres): array
    {
        $artists = [];
        $genreIds = $genres->pluck('id')->toArray();

        foreach ($artistUsers as $user) {
            $stageName = $user->_stage ?? $user->display_name;
            $slug = Str::slug($stageName);

            $artists[] = Artist::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'uuid'               => Str::uuid()->toString(),
                    'stage_name'         => $stageName,
                    'slug'               => $slug,
                    'bio'                => "Award-winning East African artist {$stageName}, known for chart-topping hits across the continent.",
                    'avatar'             => 'artists/avatars/' . $slug . '.jpg',
                    'cover_image'        => 'artists/covers/' . $slug . '.jpg',
                    'primary_genre_id'   => fake()->randomElement($genreIds),
                    'status'             => 'active',
                    'is_verified'        => true,
                    'verified_at'        => now()->subMonths(6),
                    'can_upload'         => true,
                    'auto_publish'       => false,
                    'career_start_year'  => fake()->numberBetween(2010, 2022),
                    'followers_count'    => fake()->numberBetween(500, 50000),
                    'total_plays'        => fake()->numberBetween(10000, 500000),
                    'total_revenue'      => fake()->randomFloat(2, 100000, 5000000),
                ]
            );
        }

        return $artists;
    }

    // ─────────────────────────────────────────────────────────
    //  Albums
    // ─────────────────────────────────────────────────────────
    private function seedAlbums(array $artists, $genres): array
    {
        $albums = [];
        $genreIds = $genres->pluck('id')->toArray();

        $albumTemplates = [
            ['title' => 'African Sunrise',      'type' => 'album',       'tracks' => 12],
            ['title' => 'Kampala Vibes EP',      'type' => 'ep',          'tracks' => 6],
            ['title' => 'Love & Rhythm',         'type' => 'album',       'tracks' => 10],
            ['title' => 'Midnight Sessions',     'type' => 'ep',          'tracks' => 5],
            ['title' => 'Heritage',              'type' => 'album',       'tracks' => 14],
            ['title' => 'Teso Rhythms',          'type' => 'ep',          'tracks' => 7],
            ['title' => 'Celebration',           'type' => 'compilation', 'tracks' => 16],
            ['title' => 'Back to Roots',         'type' => 'album',       'tracks' => 11],
            ['title' => 'Electric Drums',        'type' => 'ep',          'tracks' => 5],
            ['title' => 'Unity',                 'type' => 'single',      'tracks' => 1],
            ['title' => 'Firelight Sessions',    'type' => 'ep',          'tracks' => 6],
            ['title' => 'Streets of Kampala',    'type' => 'album',       'tracks' => 10],
            ['title' => 'The Journey',           'type' => 'album',       'tracks' => 13],
            ['title' => 'Pearl of Africa',       'type' => 'ep',          'tracks' => 8],
            ['title' => 'Afro Fusion Deluxe',    'type' => 'album',       'tracks' => 15],
        ];

        foreach ($artists as $idx => $artist) {
            // Each artist gets 3 albums
            for ($i = 0; $i < 3; $i++) {
                $tplIdx = ($idx * 3 + $i) % count($albumTemplates);
                $tpl = $albumTemplates[$tplIdx];
                $slug = Str::slug($tpl['title'] . '-' . $artist->slug);

                $album = Album::firstOrCreate(
                    ['slug' => $slug],
                    [
                        'uuid'                   => Str::uuid()->toString(),
                        'artist_id'              => $artist->id,
                        'title'                  => $tpl['title'],
                        'description'            => "The {$tpl['type']} \"{$tpl['title']}\" by {$artist->stage_name} — a masterful blend of East African sounds.",
                        'artwork'                => 'albums/' . $slug . '.jpg',
                        'album_type'             => $tpl['type'],
                        'release_date'           => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
                        'status'                 => 'published',
                        'is_explicit'            => fake()->boolean(15),
                        'is_free'                => fake()->boolean(40),
                        'price'                  => fake()->boolean(60) ? null : fake()->numberBetween(5000, 30000),
                        'total_tracks'           => $tpl['tracks'],
                        'total_duration_seconds' => $tpl['tracks'] * fake()->numberBetween(180, 280),
                        'play_count'             => fake()->numberBetween(500, 50000),
                        'download_count'         => fake()->numberBetween(100, 10000),
                        'like_count'             => fake()->numberBetween(50, 5000),
                    ]
                );

                $albums[] = ['album' => $album, 'artist' => $artist, 'track_count' => $tpl['tracks']];
            }
        }

        return $albums;
    }

    // ─────────────────────────────────────────────────────────
    //  Songs
    // ─────────────────────────────────────────────────────────
    private function seedSongs(array $artists, array $albumData, $genres): array
    {
        $songs = [];
        $genreIds = $genres->pluck('id')->toArray();

        $songTitles = [
            'Dancing in the Rain', 'Sunset Boulevard', 'African Queen', 'Heartbeat',
            'Moonlight Serenade', 'Fire and Ice', 'Golden Hour', 'Whispers',
            'Rhythm of the Night', 'Mama Africa', 'Destiny Calling', 'Thunder',
            'Sweet Melody', 'Rise Up', 'Echoes', 'Starlight',
            'Freedom Song', 'Love Letter', 'Paradise', 'Wild Heart',
            'Nile Groove', 'Kampala Love', 'Soroti Dreams', 'Jinja Nights',
            'Lake Victoria', 'Mountain Song', 'Savanna Beat', 'Village Dance',
            'City Lights', 'Home Again', 'Journey Within', 'Spirit of Africa',
            'Amani', 'Ubuntu', 'Twende', 'Hakuna Matata',
            'Midnight Drums', 'Sunrise Prayer', 'Street Anthem', 'Crown',
            'Buganda Beat', 'Ankole Cowbell', 'Busoga Rhythm', 'Acholi Song',
            'Lango Pride', 'Teso Heritage', 'Karamoja Wind', 'Rwenzori Mist',
        ];

        $titleIdx = 0;

        foreach ($albumData as $data) {
            $album  = $data['album'];
            $artist = $data['artist'];
            $trackCount = min($data['track_count'], 6); // cap at 6 songs per album for seeding

            for ($t = 1; $t <= $trackCount; $t++) {
                $title = $songTitles[$titleIdx % count($songTitles)];
                $titleIdx++;
                $slug = Str::slug($title . '-' . $artist->slug . '-' . $titleIdx);

                $genreId = fake()->randomElement($genreIds);
                $song = Song::firstOrCreate(
                    ['slug' => $slug],
                    [
                        'uuid'                => Str::uuid()->toString(),
                        'user_id'             => $artist->user_id,
                        'artist_id'           => $artist->id,
                        'album_id'            => $album->id,
                        'title'               => $title,
                        'description'         => "Track {$t} from \"{$album->title}\" by {$artist->stage_name}.",
                        'lyrics'              => "Verse 1:\n" . fake()->paragraph() . "\n\nChorus:\n" . fake()->sentence(8) . "\n\nVerse 2:\n" . fake()->paragraph(),
                        'audio_file_original' => 'songs/original/' . Str::uuid() . '.mp3',
                        'audio_file_320'      => 'songs/320kbps/' . Str::uuid() . '.mp3',
                        'audio_file_128'      => 'songs/128kbps/' . Str::uuid() . '.mp3',
                        'artwork'             => $album->artwork,
                        'duration_seconds'    => fake()->numberBetween(150, 320),
                        'file_size_bytes'     => fake()->numberBetween(3000000, 8000000),
                        'file_format'         => 'mp3',
                        'primary_genre_id'    => $genreId,
                        'track_number'        => $t,
                        'status'              => 'published',
                        'visibility'          => 'public',
                        'is_explicit'         => fake()->boolean(15),
                        'is_featured'         => fake()->boolean(20),
                        'is_downloadable'     => true,
                        'is_streamable'       => true,
                        'is_free'             => fake()->boolean(50),
                        'price'               => fake()->boolean(50) ? null : fake()->numberBetween(1000, 5000),
                        'currency'            => 'UGX',
                        'play_count'          => fake()->numberBetween(100, 200000),
                        'download_count'      => fake()->numberBetween(10, 20000),
                        'like_count'          => fake()->numberBetween(10, 10000),
                        'share_count'         => fake()->numberBetween(0, 3000),
                        'release_date'        => $album->release_date,
                        'published_at'        => $album->release_date,
                    ]
                );

                // Attach genre pivot
                if (Schema::hasTable('song_genres') && $song->wasRecentlyCreated) {
                    DB::table('song_genres')->insertOrIgnore([
                        'song_id'  => $song->id,
                        'genre_id' => $genreId,
                    ]);
                }

                $songs[] = $song;
            }
        }

        $this->command->info("  → {$titleIdx} songs seeded across " . count($albumData) . ' albums.');

        return $songs;
    }

    // ─────────────────────────────────────────────────────────
    //  Playlists
    // ─────────────────────────────────────────────────────────
    private function seedPlaylists(array $listeners, array $songs): void
    {
        if (! Schema::hasTable('playlists') || empty($songs)) {
            return;
        }

        $curatedPlaylists = [
            ['name' => 'Top Hits Uganda 2025',         'description' => 'The hottest tracks from Ugandan artists this year.'],
            ['name' => 'East African Vibes',            'description' => 'Curated blend of the best East African music.'],
            ['name' => 'Chill Afrobeats',               'description' => 'Laid-back Afrobeats for relaxation.'],
            ['name' => 'Gospel Praise Mix',             'description' => 'Uplifting gospel music to start your day.'],
            ['name' => 'Kampala Club Bangers',          'description' => 'High-energy tracks for the nightlife.'],
            ['name' => 'Kadongo Kamu Classics',         'description' => 'Timeless Ugandan folk classics.'],
            ['name' => 'New Releases Weekly',           'description' => 'Fresh drops from across the continent.'],
            ['name' => 'Workout Beats Africa',          'description' => 'High-tempo tracks to keep you moving.'],
        ];

        $songCollection = collect($songs);

        foreach ($curatedPlaylists as $idx => $pl) {
            $slug = Str::slug($pl['name']);
            $owner = $listeners[$idx % count($listeners)];

            $playlist = Playlist::firstOrCreate(
                ['slug' => $slug],
                [
                    'uuid'                   => Str::uuid()->toString(),
                    'user_id'                => $owner->id,
                    'name'                   => $pl['name'],
                    'description'            => $pl['description'],
                    'artwork'                => 'playlists/' . $slug . '.jpg',
                    'visibility'             => 'public',
                    'is_featured'            => $idx < 3,
                    'is_collaborative'       => false,
                    'total_tracks'           => 0,
                    'total_duration_seconds' => 0,
                    'play_count'             => fake()->numberBetween(100, 10000),
                    'follower_count'         => fake()->numberBetween(10, 2000),
                ]
            );

            // Attach random songs
            if ($playlist->wasRecentlyCreated && Schema::hasTable('playlist_songs')) {
                $playlistSongs = $songCollection->random(min(count($songs), fake()->numberBetween(5, 12)));
                $totalDuration = 0;
                $position = 1;

                foreach ($playlistSongs as $song) {
                    DB::table('playlist_songs')->insertOrIgnore([
                        'playlist_id' => $playlist->id,
                        'song_id'     => $song->id,
                        'position'    => $position++,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                    $totalDuration += $song->duration_seconds ?? 0;
                }

                $playlist->update([
                    'total_tracks'           => $playlistSongs->count(),
                    'total_duration_seconds' => $totalDuration,
                ]);
            }
        }

        $this->command->info('  → ' . count($curatedPlaylists) . ' playlists seeded.');
    }

    // ─────────────────────────────────────────────────────────
    //  Events
    // ─────────────────────────────────────────────────────────
    private function seedEvents(array $artistUsers): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        // Disable observers to avoid activities table schema issues
        Event::withoutEvents(function () use ($artistUsers) {
            $this->createEvents($artistUsers);
        });
    }

    private function createEvents(array $artistUsers): void
    {        $events = [
            ['title' => 'Afrobeats Night Kampala',          'category' => 'music',         'future' => true],
            ['title' => 'Gospel Praise Festival',            'category' => 'music',         'future' => true],
            ['title' => 'Music Production Workshop',         'category' => 'educational',   'future' => true],
            ['title' => 'Jazz Under the Stars',              'category' => 'entertainment', 'future' => true],
            ['title' => 'TesoTunes Album Launch',            'category' => 'music',         'future' => true],
            ['title' => 'Youth Music Awards 2025',           'category' => 'entertainment', 'future' => true],
            ['title' => 'Traditional Music Concert',         'category' => 'cultural',      'future' => false],
            ['title' => 'Charity Concert for Schools',       'category' => 'music',         'future' => false],
        ];

        $venues = [
            ['name' => 'Kampala Serena Hotel',     'city' => 'Kampala'],
            ['name' => 'National Theatre Uganda',  'city' => 'Kampala'],
            ['name' => 'Speke Resort Munyonyo',    'city' => 'Kampala'],
            ['name' => 'Cricket Oval Lugogo',      'city' => 'Kampala'],
            ['name' => 'Hotel Africana',           'city' => 'Kampala'],
            ['name' => 'Jinja Sailing Club',       'city' => 'Jinja'],
            ['name' => 'Mbarara Sports Club',      'city' => 'Mbarara'],
            ['name' => 'Gulu Independence Grounds','city' => 'Gulu'],
        ];

        foreach ($events as $idx => $ev) {
            $slug = Str::slug($ev['title']);
            $venue = $venues[$idx % count($venues)];
            $organizer = $artistUsers[$idx % count($artistUsers)];

            $startsAt = $ev['future']
                ? Carbon::now()->addDays(fake()->numberBetween(7, 90))
                : Carbon::now()->subDays(fake()->numberBetween(7, 60));
            $endsAt = (clone $startsAt)->addHours(fake()->numberBetween(3, 8));

            Event::firstOrCreate(
                ['slug' => $slug],
                [
                    'uuid'           => Str::uuid()->toString(),
                    'organizer_id'   => $organizer->id,
                    'organizer_type' => 'user',
                    'user_id'        => $organizer->id,
                    'title'          => $ev['title'],
                    'description'    => "Join us for {$ev['title']} — an unforgettable night of music, culture, and community at {$venue['name']}.",
                    'artwork'        => 'events/covers/' . $slug . '.jpg',
                    'category'       => $ev['category'],
                    'venue_name'     => $venue['name'],
                    'city'           => $venue['city'],
                    'country'        => 'Uganda',
                    'starts_at'      => $startsAt,
                    'ends_at'        => $endsAt,
                    'timezone'       => 'Africa/Kampala',
                    'status'         => $ev['future'] ? 'published' : 'completed',
                    'visibility'     => 'public',
                    'is_published'   => true,
                    'published_at'   => now()->subDays(30),
                    'is_featured'    => $idx < 3,
                    'is_free'        => fake()->boolean(40),
                    'ticket_price'   => fake()->boolean(60) ? fake()->numberBetween(10000, 100000) : null,
                    'currency'       => 'UGX',
                    'capacity'       => fake()->numberBetween(100, 5000),
                    'attendee_count' => fake()->numberBetween(50, 2000),
                ]
            );
        }

        $this->command->info('  → ' . count($events) . ' events seeded.');
    }

    // ─────────────────────────────────────────────────────────
    //  Posts
    // ─────────────────────────────────────────────────────────
    private function seedPosts(array $listeners, array $artistUsers, array $songs): void
    {
        if (! Schema::hasTable('posts')) {
            return;
        }

        $allUsers = array_merge($listeners, $artistUsers);
        $postContents = [
            'Just discovered this amazing track! 🔥🎶',
            'Who else is vibing to the new release? Drop a comment!',
            'Kampala music scene is on fire right now 🇺🇬',
            'My favourite song on repeat all day. Can\'t stop listening!',
            'Shoutout to all the Ugandan artists putting us on the map 🗺️🎵',
            'New music dropping soon... stay tuned! 👀',
            'This beat is insane! Producer credits please?',
            'Throwback to last night\'s concert — what an experience!',
            'Support local artists! Stream, share, and show love ❤️',
            'Late night studio sessions hitting different 🎤🌙',
            'Who\'s ready for the weekend? I\'ve got the perfect playlist!',
            'The talent in East Africa is unmatched. Period.',
            'Been on a gospel music wave lately. So uplifting!',
            'Any Kadongo Kamu lovers here? Classic never dies!',
            'That new Amapiano remix is everything! 🇿🇦🇺🇬',
        ];

        foreach ($postContents as $idx => $content) {
            $user = $allUsers[$idx % count($allUsers)];
            $hasSong = fake()->boolean(40) && ! empty($songs);

            Post::firstOrCreate(
                ['user_id' => $user->id, 'content' => $content],
                [
                    'uuid'           => Str::uuid()->toString(),
                    'song_id'        => $hasSong ? $songs[array_rand($songs)]->id : null,
                    'type'           => 'text',
                    'visibility'     => 'public',
                    'is_featured'    => fake()->boolean(15),
                    'likes_count'    => fake()->numberBetween(0, 200),
                    'comments_count' => fake()->numberBetween(0, 50),
                    'shares_count'   => fake()->numberBetween(0, 30),
                    'views_count'    => fake()->numberBetween(10, 500),
                    'published_at'   => fake()->dateTimeBetween('-30 days', 'now'),
                ]
            );
        }

        $this->command->info('  → ' . count($postContents) . ' posts seeded.');
    }

    // ─────────────────────────────────────────────────────────
    //  Feed Items
    // ─────────────────────────────────────────────────────────
    private function seedFeedItems(array $artists, array $songs, array $albumData): void
    {
        if (! Schema::hasTable('feed_items')) {
            return;
        }

        $feedItems = [];

        // Song release feed items
        foreach (array_slice($songs, 0, 20) as $song) {
            $artist = collect($artists)->firstWhere('id', $song->artist_id);
            if (! $artist) {
                continue;
            }

            $feedItems[] = [
                'uuid'                 => Str::uuid()->toString(),
                'type'                 => 'song_release',
                'module'               => 'music',
                'title'                => "{$artist->stage_name} released \"{$song->title}\"",
                'body'                 => $song->description,
                'actor_id'             => $artist->user_id,
                'actor_type'           => 'artist',
                'actor_name'           => $artist->stage_name,
                'actor_verified'       => $artist->is_verified,
                'subject_id'           => $song->id,
                'subject_type'         => 'App\\Models\\Song',
                'media_type'           => 'song',
                'media_url'            => $song->audio_file_128,
                'media_thumbnail_url'  => $song->artwork,
                'media_duration_seconds' => $song->duration_seconds,
                'likes_count'          => fake()->numberBetween(5, 500),
                'comments_count'       => fake()->numberBetween(0, 100),
                'shares_count'         => fake()->numberBetween(0, 50),
                'views_count'          => fake()->numberBetween(50, 5000),
                'visibility'           => 'public',
                'region'               => 'UG',
                'language'             => 'en',
                'published_at'         => fake()->dateTimeBetween('-30 days', 'now'),
                'created_at'           => now(),
                'updated_at'           => now(),
            ];
        }

        // Album release feed items
        foreach (array_slice($albumData, 0, 8) as $data) {
            $album  = $data['album'];
            $artist = $data['artist'];

            $feedItems[] = [
                'uuid'                => Str::uuid()->toString(),
                'type'                => 'album_release',
                'module'              => 'music',
                'title'               => "{$artist->stage_name} dropped new album \"{$album->title}\"",
                'body'                => $album->description,
                'actor_id'            => $artist->user_id,
                'actor_type'          => 'artist',
                'actor_name'          => $artist->stage_name,
                'actor_verified'      => $artist->is_verified,
                'subject_id'          => $album->id,
                'subject_type'        => 'App\\Models\\Album',
                'media_type'          => 'image',
                'media_thumbnail_url' => $album->artwork,
                'likes_count'         => fake()->numberBetween(10, 300),
                'comments_count'      => fake()->numberBetween(0, 80),
                'shares_count'        => fake()->numberBetween(0, 40),
                'views_count'         => fake()->numberBetween(100, 3000),
                'visibility'          => 'public',
                'region'              => 'UG',
                'language'            => 'en',
                'published_at'        => fake()->dateTimeBetween('-60 days', 'now'),
                'created_at'          => now(),
                'updated_at'          => now(),
            ];
        }

        // Event feed items
        $eventFeedItems = [
            ['title' => 'New event: Afrobeats Night Kampala', 'type' => 'event_created', 'module' => 'events'],
            ['title' => 'New event: Gospel Praise Festival',  'type' => 'event_created', 'module' => 'events'],
            ['title' => 'New event: Jazz Under the Stars',    'type' => 'event_created', 'module' => 'events'],
        ];

        foreach ($eventFeedItems as $ef) {
            $artist = $artists[array_rand($artists)];
            $feedItems[] = [
                'uuid'           => Str::uuid()->toString(),
                'type'           => $ef['type'],
                'module'         => $ef['module'],
                'title'          => $ef['title'],
                'body'           => 'Check out this upcoming event in Uganda!',
                'actor_id'       => $artist->user_id,
                'actor_type'     => 'user',
                'actor_name'     => $artist->stage_name,
                'actor_verified' => true,
                'visibility'     => 'public',
                'region'         => 'UG',
                'language'       => 'en',
                'likes_count'    => fake()->numberBetween(5, 100),
                'comments_count' => fake()->numberBetween(0, 30),
                'shares_count'   => fake()->numberBetween(0, 20),
                'views_count'    => fake()->numberBetween(20, 1000),
                'published_at'   => fake()->dateTimeBetween('-14 days', 'now'),
                'created_at'     => now(),
                'updated_at'     => now(),
            ];
        }

        // Insert only if not already populated
        $existingCount = FeedItem::count();
        if ($existingCount < 10) {
            foreach ($feedItems as $item) {
                FeedItem::firstOrCreate(
                    ['uuid' => $item['uuid']],
                    $item
                );
            }
            $this->command->info('  → ' . count($feedItems) . ' feed items seeded.');
        } else {
            $this->command->info("  → Skipped feed items (already {$existingCount} present).");
        }
    }

    // ─────────────────────────────────────────────────────────
    //  Payments
    // ─────────────────────────────────────────────────────────
    private function seedPayments(array $listeners, array $songs): void
    {
        if (! Schema::hasTable('payments') || empty($songs) || empty($listeners)) {
            return;
        }

        $paymentScenarios = [
            ['type' => 'purchase',     'method' => 'mobile_money', 'provider' => 'mtn_mobile_money', 'status' => 'completed'],
            ['type' => 'purchase',     'method' => 'mobile_money', 'provider' => 'airtel_money',     'status' => 'completed'],
            ['type' => 'subscription', 'method' => 'mobile_money', 'provider' => 'mtn_mobile_money', 'status' => 'completed'],
            ['type' => 'tip',          'method' => 'mobile_money', 'provider' => 'mtn_mobile_money', 'status' => 'completed'],
            ['type' => 'purchase',     'method' => 'mobile_money', 'provider' => 'mtn_mobile_money', 'status' => 'pending'],
            ['type' => 'purchase',     'method' => 'mobile_money', 'provider' => 'airtel_money',     'status' => 'failed'],
            ['type' => 'tip',          'method' => 'mobile_money', 'provider' => 'airtel_money',     'status' => 'completed'],
            ['type' => 'purchase',     'method' => 'card',         'provider' => 'flutterwave',      'status' => 'completed'],
        ];

        $existingCount = Payment::count();
        if ($existingCount >= 8) {
            $this->command->info("  → Skipped payments (already {$existingCount} present).");
            return;
        }

        // Disable observers to avoid AuditLog facade issues
        Payment::withoutEvents(function () use ($listeners, $songs, $paymentScenarios) {
            foreach ($paymentScenarios as $idx => $scenario) {
                $user = $listeners[$idx % count($listeners)];
                $song = $songs[array_rand($songs)];
                $amount = fake()->numberBetween(2000, 50000);

                $payment = new Payment();
                $payment->forceFill([
                'uuid'              => Str::uuid()->toString(),
                'user_id'           => $user->id,
                'payable_type'      => 'App\\Models\\Song',
                'payable_id'        => $song->id,
                'song_id'           => $song->id,
                'transaction_id'    => 'TXN_' . strtoupper(Str::random(12)),
                'amount'            => $amount,
                'currency'          => 'UGX',
                'payment_type'      => $scenario['type'],
                'payment_method'    => $scenario['method'],
                'provider'          => $scenario['provider'],
                'payment_provider'  => $scenario['provider'],
                'phone_number'      => '2567' . fake()->numberBetween(10000000, 99999999),
                'status'            => $scenario['status'],
                'description'       => ucfirst($scenario['type']) . " for \"{$song->title}\"",
                'initiated_at'      => now()->subDays(fake()->numberBetween(1, 30)),
                'completed_at'      => $scenario['status'] === 'completed' ? now()->subDays(fake()->numberBetween(0, 29)) : null,
                'failed_at'         => $scenario['status'] === 'failed' ? now()->subDays(fake()->numberBetween(0, 10)) : null,
                'failure_reason'    => $scenario['status'] === 'failed' ? 'Insufficient funds' : null,
            ]);
            $payment->save();
            }
        });

        $this->command->info('  → ' . count($paymentScenarios) . ' payments seeded.');
    }
}
