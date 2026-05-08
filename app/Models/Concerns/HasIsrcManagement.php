<?php

namespace App\Models\Concerns;

/**
 * ISRC assignment eligibility, blocker messages, and code generation for songs.
 *
 * Why a trait: the Song model was accumulating ~9 methods all about the same
 * sub-concern (can this song get an ISRC?). Isolating them here makes the
 * eligibility rules easy to find, test, and extend without touching core
 * Song behaviour.
 */
trait HasIsrcManagement
{
    public function hasIsrcAssigned(): bool
    {
        return filled($this->isrc_code);
    }

    public function hasAudioAssetForIsrc(): bool
    {
        return filled($this->audio_file_original)
            || filled($this->audio_file_320)
            || filled($this->audio_file_128);
    }

    public function isAuthorizedForIsrcAssignment(): bool
    {
        return in_array($this->distribution_status, ['approved', 'distributed'], true)
            || ($this->status === 'published' && $this->approved_at !== null);
    }

    /**
     * Return an array of string blocker keys, or an empty array if eligible.
     */
    public function getIsrcAssignmentBlockers(bool $ignoreExisting = false): array
    {
        $blockers = [];

        if (! $ignoreExisting && $this->hasIsrcAssigned()) {
            $blockers[] = 'already_assigned';
        }

        if (! $this->artist_id) {
            $blockers[] = 'missing_artist';
        }

        if (! filled($this->title)) {
            $blockers[] = 'missing_title';
        }

        if (! $this->hasAudioAssetForIsrc()) {
            $blockers[] = 'missing_audio';
        }

        if ((int) $this->duration_seconds <= 0) {
            $blockers[] = 'missing_duration';
        }

        if (! $this->hasValidRightsSplits()) {
            $blockers[] = 'invalid_rights';
        }

        if (! $this->isAuthorizedForIsrcAssignment()) {
            $blockers[] = 'not_authorized';
        }

        return array_values(array_unique($blockers));
    }

    public function canAssignIsrc(bool $ignoreExisting = false): bool
    {
        return $this->getIsrcAssignmentBlockers($ignoreExisting) === [];
    }

    public function getIsrcAssignmentBlockerMessages(bool $ignoreExisting = false): array
    {
        return array_map(
            fn (string $blocker) => self::isrcAssignmentBlockerMessage($blocker),
            $this->getIsrcAssignmentBlockers($ignoreExisting)
        );
    }

    public static function isrcAssignmentBlockerMessage(string $blocker): string
    {
        return match ($blocker) {
            'already_assigned' => 'This song already has an ISRC assigned.',
            'missing_artist' => 'Assign an artist before generating an ISRC.',
            'missing_title' => 'Add a song title before generating an ISRC.',
            'missing_audio' => 'Upload a source audio file before generating an ISRC.',
            'missing_duration' => 'Duration metadata must be captured before generating an ISRC.',
            'invalid_rights' => 'Ownership and rights splits must be valid before generating an ISRC.',
            'not_authorized' => 'This song must be approved for release or distribution before an ISRC can be assigned.',
            default => 'This song is not yet eligible for ISRC assignment.',
        };
    }

    public function getIsrcAssignmentSummary(): array
    {
        $blockers = $this->getIsrcAssignmentBlockers();
        $assigned = $this->hasIsrcAssigned();
        $eligible = ! $assigned && $blockers === [];

        return [
            'assigned' => $assigned,
            'eligible' => $eligible,
            'status' => $assigned ? 'assigned' : ($eligible ? 'eligible' : 'blocked'),
            'code' => $this->isrc_code,
            'blockers' => $blockers,
            'blocker_messages' => array_map(
                fn (string $blocker) => self::isrcAssignmentBlockerMessage($blocker),
                $blockers
            ),
        ];
    }

    /**
     * Generate a local ISRC code for this song (Uganda format: UG-XXX-YY-NNNNN).
     * The canonical implementation is in ISRCService — this is a convenience fallback.
     */
    public function generateISRCCode(): string
    {
        if ($this->isrc_code) {
            return $this->isrc_code;
        }

        $registrantCode = str_pad(
            substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($this->artist->name ?? 'UNK')), 0, 3),
            3,
            '0'
        );

        return sprintf('UG-%s-%s-%s', $registrantCode, now()->format('y'), str_pad($this->id, 5, '0', STR_PAD_LEFT));
    }
}
