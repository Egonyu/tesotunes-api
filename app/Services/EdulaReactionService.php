<?php

namespace App\Services;

use App\Models\EdulaReaction;
use App\Models\User;
use App\Settings\SettingsManager;
use Illuminate\Support\Facades\DB;

/**
 * One-tap reactions for every Edula timeline item.
 *
 * The reaction set comes from the public setting `general_edula_reactions`
 * ("key:Label,key:Label"), so wording in Ateso can be corrected by an
 * operator without a release. Counts are public; who reacted is not.
 */
class EdulaReactionService
{
    public const IDENTITY = '/^(feed:[a-fA-F0-9-]{36}|(?:song|episode|post|event|poll|product|brief|corpus|deal):[1-9][0-9]*|project:ateso)$/';

    public function __construct(
        private SettingsManager $settings,
        private TimelinePreferenceService $preferences,
    ) {}

    /** @return array<string, string> reaction key => label, in the operator's order */
    public function options(): array
    {
        $options = [];
        foreach (explode(',', (string) $this->settings->get('general_edula_reactions')) as $pair) {
            [$key, $label] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');
            if (preg_match('/^[a-z][a-z0-9_]{0,29}$/', $key) && $label !== '') {
                $options[$key] = mb_substr($label, 0, 40);
            }
        }

        return $options;
    }

    /**
     * Counts per reaction and the viewer's own reaction, for a page of items.
     * Reactions the operator has since retired are not counted.
     *
     * @param  array<int, string>  $identities
     * @return array<string, array{counts: array<string, int>, mine: ?string}>
     */
    public function summaries(array $identities, ?User $viewer): array
    {
        $identities = array_values(array_unique(array_filter($identities, fn ($identity) => is_string($identity) && preg_match(self::IDENTITY, $identity))));
        $keys = array_keys($this->options());
        $summaries = array_fill_keys($identities, ['counts' => (object) [], 'mine' => null]);
        if (! $identities || ! $keys) {
            return $summaries;
        }

        $counts = EdulaReaction::query()->whereIn('identity', $identities)->whereIn('reaction', $keys)
            ->select('identity', 'reaction', DB::raw('COUNT(*) as total'))->groupBy('identity', 'reaction')->get();
        $grouped = [];
        foreach ($counts as $row) {
            $grouped[$row->identity][$row->reaction] = (int) $row->total;
        }
        $mine = $viewer
            ? EdulaReaction::query()->where('user_id', $viewer->id)->whereIn('identity', $identities)->whereIn('reaction', $keys)->pluck('reaction', 'identity')->all()
            : [];
        foreach ($identities as $identity) {
            $summaries[$identity] = [
                'counts' => (object) ($grouped[$identity] ?? []),
                'mine' => $mine[$identity] ?? null,
            ];
        }

        return $summaries;
    }

    /**
     * Set, change or clear (null) the member's reaction to one item.
     *
     * @return array{counts: array<string, int>, mine: ?string}
     */
    public function react(User $user, string $identity, ?string $reaction): array
    {
        abort_unless(preg_match(self::IDENTITY, $identity), 422, 'Choose a valid timeline item.');
        abort_if($reaction !== null && ! array_key_exists($reaction, $this->options()), 422, 'Choose one of the available reactions.');

        if ($reaction === null) {
            EdulaReaction::query()->where('user_id', $user->id)->where('identity', $identity)->delete();
        } else {
            abort_unless($this->preferences->available($identity, $user), 404, 'This content is no longer available.');
            EdulaReaction::query()->updateOrCreate(['user_id' => $user->id, 'identity' => $identity], ['reaction' => $reaction]);
        }

        return $this->summaries([$identity], $user)[$identity];
    }
}
