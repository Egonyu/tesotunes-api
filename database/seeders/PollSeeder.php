<?php

namespace Database\Seeders;

use App\Models\Modules\Forum\Poll;
use App\Models\Modules\Forum\PollAnswer;
use App\Models\Modules\Forum\PollOption;
use App\Models\Modules\Forum\PollQuestion;
use App\Models\Modules\Forum\PollResponse;
use App\Models\User;
use Illuminate\Database\Seeder;

class PollSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'poll-admin@tesotunes.test')->first()
            ?? User::factory()->create(['email' => 'poll-admin@tesotunes.test', 'display_name' => 'TesoTunes Team']);
        $users = User::factory(10)->create();

        // ── 1. ACTIVE — Song Battle (results hidden until vote, guests ok) ────
        $this->seedActiveSongBattle($admin, $users);

        // ── 2. ACTIVE — General Poll (results always visible, guests ok) ──────
        $this->seedActiveGeneralPoll($admin, $users);

        // ── 3. ACTIVE — Artist Contest (auth-only, results visible) ───────────
        $this->seedActiveArtistContest($admin, $users);

        // ── 4. ACTIVE — Research Survey (multi-question, auth-only) ───────────
        $this->seedActiveResearchSurvey($admin, $users);

        // ── 5. CLOSED — Song Battle (with winner) ─────────────────────────────
        $this->seedClosedSongBattle($admin);

        // ── 6. CLOSED — General Poll (community result) ───────────────────────
        $this->seedClosedGeneralPoll($admin);

        // ── 7. CLOSED — Artist Contest (large participation) ─────────────────
        $this->seedClosedArtistContest($admin);

        // ── 8. CLOSED — Research Survey (full multi-question results) ─────────
        $this->seedClosedResearchSurvey($admin, $users);

        // ── 9. DRAFT — Upcoming Poll (not publicly visible) ───────────────────
        Poll::factory()->draft()->create([
            'user_id' => $admin->id,
            'title' => 'Upcoming: Song of the Year 2026',
            'description' => 'Cast your vote for the best TesoTunes track of 2026.',
            'poll_type' => Poll::TYPE_GENERAL,
            'category' => 'fan_choice',
            'audience' => Poll::AUDIENCE_ALL,
            'ends_at' => now()->addDays(30),
        ]);

        $this->command->info('PollSeeder: 9 polls seeded — 4 active, 4 closed, 1 draft. Full picture ready.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function seedActiveSongBattle(User $admin, $users): void
    {
        $poll = Poll::factory()->create([
            'user_id' => $admin->id,
            'title' => 'District Showdown: Lira vs Soroti',
            'description' => 'Which district produces the best Ateso music scene right now?',
            'poll_type' => Poll::TYPE_SONG_BATTLE,
            'category' => 'district_showdown',
            'audience' => Poll::AUDIENCE_ALL,
            'allow_guest_responses' => true,
            'show_results_before_completion' => false,
            'credits_reward' => 5,
            'status' => Poll::STATUS_ACTIVE,
            'ends_at' => now()->addDays(7),
        ]);

        $q = PollQuestion::factory()->create([
            'poll_id' => $poll->id,
            'position' => 1,
            'question_text' => 'Which district has the hottest music scene right now?',
            'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE,
            'is_required' => true,
            'allow_multiple' => false,
        ]);

        $lira = PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'Lira',   'position' => 1, 'response_count' => 0]);
        $soroti = PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'Soroti', 'position' => 2, 'response_count' => 0]);
        $mbale = PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'Mbale',  'position' => 3, 'response_count' => 0]);
        $kumi = PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'Kumi',   'position' => 4, 'response_count' => 0]);

        $votes = [$lira, $lira, $soroti, $lira, $mbale, $soroti, $kumi];
        foreach ($users->take(6) as $i => $user) {
            $opt = $votes[$i];
            $resp = PollResponse::factory()->create(['poll_id' => $poll->id, 'user_id' => $user->id]);
            PollAnswer::create(['response_id' => $resp->id, 'question_id' => $q->id, 'option_id' => $opt->id]);
            $opt->increment('response_count');
        }

        // Guest response
        $guestResp = PollResponse::factory()->guest()->create(['poll_id' => $poll->id]);
        PollAnswer::create(['response_id' => $guestResp->id, 'question_id' => $q->id, 'option_id' => $soroti->id]);
        $soroti->increment('response_count');

        $poll->update(['total_responses' => 7]);
    }

    private function seedActiveGeneralPoll(User $admin, $users): void
    {
        $poll = Poll::factory()->create([
            'user_id' => $admin->id,
            'title' => 'What feature do you want most on TesoTunes?',
            'description' => 'Help us prioritize what to build next.',
            'poll_type' => Poll::TYPE_GENERAL,
            'category' => 'fan_choice',
            'audience' => Poll::AUDIENCE_ALL,
            'allow_guest_responses' => true,
            'show_results_before_completion' => true,
            'credits_reward' => 3,
            'status' => Poll::STATUS_ACTIVE,
            'ends_at' => now()->addDays(5),
        ]);

        $q = PollQuestion::factory()->create([
            'poll_id' => $poll->id,
            'position' => 1,
            'question_text' => 'Which feature would you most like to see added next?',
            'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE,
            'is_required' => true,
            'allow_multiple' => false,
        ]);

        $opts = [
            ['text' => 'Offline playback & downloads', 'count' => 14],
            ['text' => 'Lyrics display',                'count' => 9],
            ['text' => 'Social playlists with friends', 'count' => 7],
            ['text' => 'Podcast support',               'count' => 4],
            ['text' => 'Live streaming concerts',       'count' => 3],
        ];

        $total = 0;
        foreach ($opts as $pos => $o) {
            PollOption::factory()->create([
                'question_id' => $q->id,
                'option_text' => $o['text'],
                'position' => $pos + 1,
                'response_count' => $o['count'],
            ]);
            $total += $o['count'];
        }

        $poll->update(['total_responses' => $total]);
    }

    private function seedActiveArtistContest(User $admin, $users): void
    {
        $poll = Poll::factory()->artistContest()->create([
            'user_id' => $admin->id,
            'title' => 'Rising Star: April 2026 Fan Vote',
            'description' => 'Vote for the breakout artist of the month.',
            'category' => 'rising_star',
            'audience' => Poll::AUDIENCE_USERS,
            'allow_guest_responses' => false,
            'show_results_before_completion' => true,
            'credits_reward' => 5,
            'status' => Poll::STATUS_ACTIVE,
            'ends_at' => now()->addDays(10),
        ]);

        $q = PollQuestion::factory()->create([
            'poll_id' => $poll->id,
            'position' => 1,
            'question_text' => 'Who is your rising star of April?',
            'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE,
            'is_required' => true,
            'allow_multiple' => false,
        ]);

        $contestants = [
            ['name' => 'Atim Grace',      'count' => 18],
            ['name' => 'Opio MC',         'count' => 15],
            ['name' => 'DJ Oulanyah',     'count' => 11],
            ['name' => 'Emojong Beatbox', 'count' => 6],
        ];

        $total = 0;
        foreach ($contestants as $pos => $c) {
            PollOption::factory()->create([
                'question_id' => $q->id,
                'option_text' => $c['name'],
                'position' => $pos + 1,
                'response_count' => $c['count'],
            ]);
            $total += $c['count'];
        }

        // Log actual responses for a few users
        $optionModels = PollOption::where('question_id', $q->id)->get();
        foreach ($users->take(4) as $i => $user) {
            $opt = $optionModels[$i % $optionModels->count()];
            $resp = PollResponse::factory()->create(['poll_id' => $poll->id, 'user_id' => $user->id]);
            PollAnswer::create(['response_id' => $resp->id, 'question_id' => $q->id, 'option_id' => $opt->id]);
        }

        $poll->update(['total_responses' => $total]);
    }

    private function seedActiveResearchSurvey(User $admin, $users): void
    {
        $survey = Poll::factory()->researchSurvey()->create([
            'user_id' => $admin->id,
            'title' => 'TesoTunes Listener Experience Survey',
            'description' => 'Help us understand how you use TesoTunes. Takes ~3 minutes.',
            'audience' => Poll::AUDIENCE_USERS,
            'allow_guest_responses' => false,
            'show_results_before_completion' => false,
            'credits_reward' => 10,
            'status' => Poll::STATUS_ACTIVE,
            'ends_at' => now()->addDays(14),
        ]);

        // Q1: Rating — audio quality
        $q1 = PollQuestion::factory()->rating()->create([
            'poll_id' => $survey->id,
            'position' => 1,
            'question_text' => 'How would you rate the audio quality on TesoTunes? (1–10)',
            'is_required' => true,
        ]);

        // Q2: Likert — recommendation
        $q2 = PollQuestion::factory()->likert()->create([
            'poll_id' => $survey->id,
            'position' => 2,
            'question_text' => 'I would recommend TesoTunes to a friend.',
            'is_required' => true,
        ]);

        // Q3: Multiple choice (single) — usage frequency
        $q3 = PollQuestion::factory()->create([
            'poll_id' => $survey->id,
            'position' => 3,
            'question_text' => 'How often do you use TesoTunes?',
            'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE,
            'allow_multiple' => false,
            'is_required' => true,
        ]);
        $freq = [
            ['text' => 'Every day',    'count' => 3],
            ['text' => 'A few times a week', 'count' => 1],
            ['text' => 'Weekly',       'count' => 1],
            ['text' => 'Occasionally', 'count' => 0],
        ];
        $freqOptions = [];
        foreach ($freq as $pos => $f) {
            $freqOptions[] = PollOption::factory()->create([
                'question_id' => $q3->id,
                'option_text' => $f['text'],
                'position' => $pos + 1,
                'response_count' => $f['count'],
            ]);
        }

        // Q4: Multiple choice (multi-select) — genres
        $q4 = PollQuestion::factory()->create([
            'poll_id' => $survey->id,
            'position' => 4,
            'question_text' => 'Which genres do you listen to most? (Select all that apply)',
            'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE,
            'allow_multiple' => true,
            'is_required' => true,
        ]);
        $genres = [
            ['text' => 'Afrobeats',        'count' => 4],
            ['text' => 'Teso Traditional', 'count' => 5],
            ['text' => 'Gospel',           'count' => 3],
            ['text' => 'Hip-Hop',          'count' => 2],
            ['text' => 'R&B',              'count' => 2],
        ];
        $genreOptions = [];
        foreach ($genres as $pos => $g) {
            $genreOptions[] = PollOption::factory()->create([
                'question_id' => $q4->id,
                'option_text' => $g['text'],
                'position' => $pos + 1,
                'response_count' => $g['count'],
            ]);
        }

        // Q5: Free text — open feedback (optional)
        $q5 = PollQuestion::factory()->freeText()->optional()->create([
            'poll_id' => $survey->id,
            'position' => 5,
            'question_text' => 'What one thing would make TesoTunes better?',
        ]);

        // Create 5 real responses
        $ratingValues = [8, 9, 7, 10, 6];
        $likertValues = [5, 4, 5, 5, 3];
        $freqChoices = [0, 0, 1, 0, 2];
        $genreChoices = [[0, 1], [1, 2], [0, 3], [1, 4], [0, 1, 2]];
        $textAnswers = ['Offline mode!', 'Better search', 'Group playlists', 'Lyrics display', null];

        foreach ($users->take(5) as $i => $user) {
            $resp = PollResponse::factory()->create(['poll_id' => $survey->id, 'user_id' => $user->id]);

            PollAnswer::create(['response_id' => $resp->id, 'question_id' => $q1->id, 'rating_value' => $ratingValues[$i]]);
            PollAnswer::create(['response_id' => $resp->id, 'question_id' => $q2->id, 'rating_value' => $likertValues[$i]]);
            PollAnswer::create(['response_id' => $resp->id, 'question_id' => $q3->id, 'option_id' => $freqOptions[$freqChoices[$i]]->id]);

            foreach ($genreChoices[$i] as $gi) {
                PollAnswer::create(['response_id' => $resp->id, 'question_id' => $q4->id, 'option_id' => $genreOptions[$gi]->id]);
            }

            if ($textAnswers[$i] !== null) {
                PollAnswer::create(['response_id' => $resp->id, 'question_id' => $q5->id, 'answer_text' => $textAnswers[$i]]);
            }
        }

        $survey->update(['total_responses' => 5]);
    }

    private function seedClosedSongBattle(User $admin): void
    {
        $poll = Poll::factory()->closed()->create([
            'user_id' => $admin->id,
            'title' => 'The Great Teso Anthem Showdown',
            'description' => 'Fans voted for the ultimate Ateso anthem. Results are in!',
            'poll_type' => Poll::TYPE_SONG_BATTLE,
            'category' => 'ateso_vs_english',
            'audience' => Poll::AUDIENCE_ALL,
            'allow_guest_responses' => true,
            'show_results_before_completion' => true,
            'credits_reward' => 5,
            'ends_at' => now()->subDays(3),
            'total_responses' => 312,
        ]);

        $q = PollQuestion::factory()->create([
            'poll_id' => $poll->id,
            'position' => 1,
            'question_text' => 'Which track is the ultimate Ateso anthem?',
            'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE,
            'is_required' => true,
        ]);

        PollOption::factory()->create(['question_id' => $q->id, 'option_text' => '"Akello" by Opio MC',     'position' => 1, 'response_count' => 148]);
        PollOption::factory()->create(['question_id' => $q->id, 'option_text' => '"Eseja" by Atim Grace',   'position' => 2, 'response_count' => 97]);
        PollOption::factory()->create(['question_id' => $q->id, 'option_text' => '"Abia" by Emojong',       'position' => 3, 'response_count' => 45]);
        PollOption::factory()->create(['question_id' => $q->id, 'option_text' => '"Ngeyo" by DJ Oulanyah',  'position' => 4, 'response_count' => 22]);
    }

    private function seedClosedGeneralPoll(User $admin): void
    {
        $poll = Poll::factory()->closed()->create([
            'user_id' => $admin->id,
            'title' => 'Which TesoTunes feature do you use most?',
            'description' => 'Closed poll — thanks for your responses!',
            'poll_type' => Poll::TYPE_GENERAL,
            'category' => 'general',
            'audience' => Poll::AUDIENCE_ALL,
            'allow_guest_responses' => true,
            'show_results_before_completion' => true,
            'credits_reward' => 3,
            'ends_at' => now()->subWeek(),
            'total_responses' => 89,
        ]);

        $q = PollQuestion::factory()->create([
            'poll_id' => $poll->id,
            'position' => 1,
            'question_text' => 'What do you use TesoTunes for most?',
            'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE,
            'is_required' => true,
        ]);

        PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'Streaming music',        'position' => 1, 'response_count' => 41]);
        PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'Downloading tracks',     'position' => 2, 'response_count' => 27]);
        PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'Discovering new artists', 'position' => 3, 'response_count' => 15]);
        PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'Community polls',        'position' => 4, 'response_count' => 6]);
    }

    private function seedClosedArtistContest(User $admin): void
    {
        $poll = Poll::factory()->closed()->create([
            'user_id' => $admin->id,
            'title' => 'Artist of the Year — 2025',
            'description' => 'The community has spoken! Congratulations to all nominees.',
            'poll_type' => Poll::TYPE_ARTIST_CONTEST,
            'category' => 'fan_choice',
            'audience' => Poll::AUDIENCE_ALL,
            'allow_guest_responses' => true,
            'show_results_before_completion' => true,
            'credits_reward' => 10,
            'ends_at' => now()->subMonth(),
            'total_responses' => 1847,
        ]);

        $q = PollQuestion::factory()->create([
            'poll_id' => $poll->id,
            'position' => 1,
            'question_text' => 'Who is your TesoTunes Artist of the Year for 2025?',
            'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE,
            'is_required' => true,
        ]);

        PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'Atim Grace',      'position' => 1, 'response_count' => 782]);
        PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'Opio MC',         'position' => 2, 'response_count' => 541]);
        PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'DJ Oulanyah',     'position' => 3, 'response_count' => 312]);
        PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'Emojong Beatbox', 'position' => 4, 'response_count' => 145]);
        PollOption::factory()->create(['question_id' => $q->id, 'option_text' => 'Aciro Sonia',     'position' => 5, 'response_count' => 67]);
    }

    private function seedClosedResearchSurvey(User $admin, $users): void
    {
        $survey = Poll::factory()->researchSurvey()->closed()->create([
            'user_id' => $admin->id,
            'title' => 'Platform Feedback Survey — Q4 2025',
            'description' => 'Thank you to all 156 respondents! Results are used to plan our 2026 roadmap.',
            'audience' => Poll::AUDIENCE_USERS,
            'allow_guest_responses' => false,
            'show_results_before_completion' => false,
            'credits_reward' => 15,
            'ends_at' => now()->subDays(10),
            'total_responses' => 156,
        ]);

        // Q1: Overall rating (1–10)
        $q1 = PollQuestion::factory()->rating()->create([
            'poll_id' => $survey->id,
            'position' => 1,
            'question_text' => 'Overall, how satisfied are you with TesoTunes? (1–10)',
            'is_required' => true,
        ]);

        // Q2: NPS-style (would you recommend?)
        $q2 = PollQuestion::factory()->likert()->create([
            'poll_id' => $survey->id,
            'position' => 2,
            'question_text' => 'TesoTunes represents African music better than other platforms.',
            'is_required' => true,
        ]);

        // Q3: Most loved feature
        $q3 = PollQuestion::factory()->create([
            'poll_id' => $survey->id,
            'position' => 3,
            'question_text' => 'What is your favourite thing about TesoTunes?',
            'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE,
            'allow_multiple' => false,
            'is_required' => true,
        ]);
        foreach ([
            ['text' => 'African music selection',   'count' => 68],
            ['text' => 'Sound quality',             'count' => 35],
            ['text' => 'Credits & rewards system',  'count' => 28],
            ['text' => 'Community polls & events',  'count' => 15],
            ['text' => 'Artist discovery',          'count' => 10],
        ] as $pos => $o) {
            PollOption::factory()->create([
                'question_id' => $q3->id,
                'option_text' => $o['text'],
                'position' => $pos + 1,
                'response_count' => $o['count'],
            ]);
        }

        // Q4: Pain points (multi-select)
        $q4 = PollQuestion::factory()->create([
            'poll_id' => $survey->id,
            'position' => 4,
            'question_text' => 'What would you most like us to improve?',
            'question_type' => PollQuestion::TYPE_MULTIPLE_CHOICE,
            'allow_multiple' => true,
            'is_required' => true,
        ]);
        foreach ([
            ['text' => 'Offline playback',        'count' => 89],
            ['text' => 'Larger music catalogue',  'count' => 72],
            ['text' => 'Better search filters',   'count' => 54],
            ['text' => 'Mobile app stability',    'count' => 41],
            ['text' => 'Faster loading',          'count' => 38],
        ] as $pos => $o) {
            PollOption::factory()->create([
                'question_id' => $q4->id,
                'option_text' => $o['text'],
                'position' => $pos + 1,
                'response_count' => $o['count'],
            ]);
        }

        // Q5: Free-text testimonial (optional)
        $q5 = PollQuestion::factory()->freeText()->optional()->create([
            'poll_id' => $survey->id,
            'position' => 5,
            'question_text' => 'Anything else you would like to share with the TesoTunes team?',
        ]);

        // Create a sample of real responses from known users
        $ratings = [9, 8, 10, 7, 8];
        $likerts = [5, 4, 5, 4, 5];
        $texts = [
            'Keep up the amazing work — we love TesoTunes!',
            'Offline mode is a must for us in areas with poor connectivity.',
            'Best platform for African music hands down.',
            'Please add more Teso language songs.',
            null,
        ];

        foreach ($users->take(5) as $i => $user) {
            $resp = PollResponse::factory()->create(['poll_id' => $survey->id, 'user_id' => $user->id]);
            PollAnswer::create(['response_id' => $resp->id, 'question_id' => $q1->id, 'rating_value' => $ratings[$i]]);
            PollAnswer::create(['response_id' => $resp->id, 'question_id' => $q2->id, 'rating_value' => $likerts[$i]]);

            if ($texts[$i] !== null) {
                PollAnswer::create(['response_id' => $resp->id, 'question_id' => $q5->id, 'answer_text' => $texts[$i]]);
            }
        }
    }
}
