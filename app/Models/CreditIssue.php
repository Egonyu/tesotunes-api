<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A credit movement that should have happened and did not.
 *
 * The credits ledger is double-entry and reconciles exactly, which sounds like
 * safety and is not: a ledger can only record what arrived. When four award
 * paths silently skipped every account with no wallet row, the ledger stayed
 * perfectly balanced for four months while 875 credits' worth of tips never
 * reached the artists they were sent to. Nothing was wrong with the books;
 * the entries were simply never written.
 *
 * This is the record of the entry that never got written. The vocabulary
 * deliberately mirrors {@see PaymentIssue} so an operator reading the issues
 * queue does not have to learn two systems.
 */
class CreditIssue extends Model
{
    use HasFactory;

    /** The award never reached the account at all. */
    public const TYPE_AWARD_FAILED = 'award_failed';

    /** The account had no wallet, so the award had nowhere to land. */
    public const TYPE_MISSING_WALLET = 'missing_wallet';

    /** Credits were spent but the counterparty was never credited. */
    public const TYPE_TRANSFER_INCOMPLETE = 'transfer_incomplete';

    /** The amount awarded does not match what the source called for. */
    public const TYPE_AMOUNT_MISMATCH = 'amount_mismatch';

    /** The same award was written more than once. */
    public const TYPE_DUPLICATE_AWARD = 'duplicate_award';

    /** Wallet balance disagrees with the sum of its transactions. */
    public const TYPE_BALANCE_DRIFT = 'balance_drift';

    /** A daily earning limit stopped an award the user had expected. */
    public const TYPE_LIMIT_BLOCKED = 'limit_blocked';

    public const STATUS_OPEN = 'open';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_ESCALATED = 'escalated';

    public const STATUS_CLOSED = 'closed';

    public const RESOLUTION_AUTO_RESOLVED = 'auto_resolved';

    public const RESOLUTION_MANUAL = 'manual';

    public const RESOLUTION_BACKFILLED = 'backfilled';

    public const RESOLUTION_RETRIED = 'retried';

    public const RESOLUTION_FALSE_POSITIVE = 'false_positive';

    protected $fillable = [
        'user_id',
        'credit_transaction_id',
        'sourceable_type',
        'sourceable_id',
        'issue_type',
        'source',
        'amount',
        'title',
        'description',
        'status',
        'severity',
        'credits_awarded',
        'resolution_type',
        'resolution_notes',
        'resolved_at',
        'resolved_by',
        'metadata',
        'auto_resolve_attempts',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'credits_awarded' => 'boolean',
            'metadata' => 'array',
            'resolved_at' => 'datetime',
            'auto_resolve_attempts' => 'integer',
        ];
    }

    /** The account that should have been credited. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The ledger row, when there is one. Usually there is not — that is the
     * point of the record.
     */
    public function creditTransaction(): BelongsTo
    {
        return $this->belongsTo(CreditTransaction::class);
    }

    /** What the credits were for: the tip payment, the play, the top-up. */
    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeUnresolved($query)
    {
        return $query->whereNotIn('status', [self::STATUS_RESOLVED, self::STATUS_CLOSED]);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('issue_type', $type);
    }

    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeHighSeverity($query)
    {
        return $query->whereIn('severity', ['critical', 'high']);
    }

    /** Issues where the user is still out of pocket. */
    public function scopeStillOwed($query)
    {
        return $query->where('credits_awarded', false)
            ->whereNotIn('status', [self::STATUS_RESOLVED, self::STATUS_CLOSED]);
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }

    public function markAsResolved(string $resolutionType, string $notes = '', ?int $resolvedBy = null): void
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolution_type' => $resolutionType,
            'resolution_notes' => $notes,
            'resolved_at' => now(),
            'resolved_by' => $resolvedBy,
        ]);
    }

    /** The credits finally landed — record that alongside the resolution. */
    public function markAwarded(?CreditTransaction $transaction = null, string $notes = ''): void
    {
        $this->update([
            'credits_awarded' => true,
            'credit_transaction_id' => $transaction?->id ?? $this->credit_transaction_id,
            'status' => self::STATUS_RESOLVED,
            'resolution_type' => self::RESOLUTION_BACKFILLED,
            'resolution_notes' => $notes,
            'resolved_at' => now(),
        ]);
    }

    public function escalate(): void
    {
        $this->update(['status' => self::STATUS_ESCALATED]);
    }

    public function incrementAutoResolveAttempts(): void
    {
        $this->increment('auto_resolve_attempts');
    }
}
