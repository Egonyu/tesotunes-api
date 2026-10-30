<?php

namespace App\Services\Admin\UserAudit;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the admin "User 360" audit view for one user.
 *
 * Read-only. Every query selects an explicit column list so secrets (OAuth tokens,
 * wallet PIN hashes, 2FA secrets, KYC file paths) can never reach the response.
 */
class UserAuditService
{
    public const SECTIONS = ['identity', 'money', 'kyc', 'contributions', 'listening', 'network', 'security'];

    public function __construct(private readonly UserAuditFlagEvaluator $flags) {}

    public function build(User $user, bool $reveal = false, ?array $sections = null): array
    {
        $profile = DB::table('users')->where('id', $user->id)->first(['phone', 'phone_number']);
        $profilePhone = $this->phoneKey($profile->phone ?? $profile->phone_number ?? null);

        $data = [
            'identity' => $this->identity($user->id),
            'money' => $this->money($user->id, $profilePhone, $reveal),
            'kyc' => $this->kyc($user->id),
            'contributions' => $this->contributions($user->id),
            'listening' => $this->listening($user->id),
            'network' => $this->network($user->id, $reveal),
            'security' => $this->security($user->id),
        ];

        $data['identity']['phone'] = $this->phone($profile->phone ?? $profile->phone_number ?? null, $reveal);

        $assessment = $this->flags->evaluate($data);
        $wanted = $sections ? array_values(array_intersect(self::SECTIONS, $sections)) : self::SECTIONS;

        return [
            'generated_at' => now()->toIso8601String(),
            'pii_revealed' => $reveal,
            ...$assessment,
            ...array_intersect_key($data, array_flip($wanted)),
        ];
    }

    public function timeline(User $user, array $filters = []): array
    {
        $perPage = min(
            max((int) ($filters['per_page'] ?? config('user_audit.timeline.default_per_page', 50)), 1),
            (int) config('user_audit.timeline.max_per_page', 100)
        );
        $page = max((int) ($filters['page'] ?? 1), 1);
        $limit = $perPage * $page;
        $kinds = $filters['kinds'] ?? ['audit', 'security', 'kyc', 'credits'];
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $noise = ! empty($filters['include_noise']) ? [] : config('user_audit.timeline.noise_categories', []);

        $sources = [
            'audit' => fn () => DB::table('audit_logs')->where('user_id', $user->id)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->select(['id', 'created_at as at', 'action', 'auditable_type', 'auditable_id', 'ip_address']),
            'security' => fn () => DB::table('observability_events')->where('actor_id', $user->id)
                ->when($noise, fn ($q) => $q->whereNotIn('category', $noise))
                ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to))
                ->select(['id', 'occurred_at as at', 'domain', 'category', 'outcome', 'severity', 'title', 'target_route', 'risk_score', 'source_ip']),
            'kyc' => fn () => DB::table('kyc_documents')->where('user_id', $user->id)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->select(['id', 'created_at as at', 'document_type', 'status', 'verified_at', 'verified_by', 'rejection_reason', 'ip_address']),
            'credits' => fn () => DB::table('credit_transactions')->where('user_id', $user->id)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->select(['id', 'created_at as at', 'type', 'source', 'amount', 'balance_after', 'description']),
        ];

        $total = 0;
        $events = collect();

        foreach (array_intersect_key($sources, array_flip($kinds)) as $kind => $query) {
            $q = $query();
            $total += (clone $q)->count();
            $events = $events->merge(
                $q->orderByDesc('at')->limit($limit)->get()->map(fn ($row) => $this->timelineRow($kind, $row))
            );
        }

        return [
            'data' => $events->sortByDesc('at')->values()->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => (int) max(ceil($total / $perPage), 1)],
        ];
    }

    private function timelineRow(string $kind, object $row): array
    {
        $at = Carbon::parse($row->at)->toIso8601String();

        return match ($kind) {
            'audit' => [
                'kind' => 'audit', 'at' => $at, 'severity' => 'info', 'title' => $row->action,
                'detail' => $row->auditable_type ? class_basename($row->auditable_type).'#'.$row->auditable_id : null,
                'ip' => $row->ip_address,
            ],
            'security' => [
                'kind' => 'security', 'at' => $at, 'severity' => $row->severity, 'title' => $row->title,
                'detail' => trim("{$row->category} / {$row->outcome}".($row->target_route ? " {$row->target_route}" : '')),
                'risk_score' => $row->risk_score, 'ip' => $row->source_ip,
            ],
            'kyc' => [
                'kind' => 'kyc', 'at' => $at, 'severity' => $row->status === 'rejected' ? 'medium' : 'info',
                'title' => "KYC {$row->document_type} {$row->status}",
                'detail' => $row->rejection_reason ?: ($row->verified_at ? 'verified '.Carbon::parse($row->verified_at)->toIso8601String() : null),
                'ip' => $row->ip_address,
            ],
            'credits' => [
                'kind' => 'credits', 'at' => $at, 'severity' => 'info', 'title' => "{$row->type}: {$row->source}",
                'detail' => rtrim(rtrim(number_format((float) $row->amount, 2, '.', ''), '0'), '.').' credits'.($row->description ? " - {$row->description}" : ''),
            ],
        };
    }

    private function identity(int $id): array
    {
        $u = DB::table('users')->where('id', $id)->first([
            'id', 'uuid', 'username', 'display_name', 'email', 'provider', 'country', 'role', 'status', 'is_active',
            'created_at', 'last_login_at', 'last_seen_at', 'email_verified_at', 'phone_verified_at', 'kyc_status',
            'is_artist', 'wallet_pin_set_at', 'wallet_pin_failed_attempts', 'wallet_pin_locked_until', 'credits', 'ugx_balance',
        ]);

        return [
            'id' => $u->id,
            'uuid' => $u->uuid,
            'username' => $u->username,
            'display_name' => $u->display_name,
            'email' => $u->email,
            'sign_up_method' => $u->provider ?: 'email',
            'country' => $u->country,
            'role' => $u->role,
            'roles' => DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('user_roles.user_id', $id)->where('user_roles.is_active', 1)->pluck('roles.name')->all(),
            'status' => $u->status,
            'is_active' => (bool) $u->is_active,
            'is_artist' => (bool) $u->is_artist,
            'created_at' => $this->iso($u->created_at),
            'last_login_at' => $this->iso($u->last_login_at),
            'last_seen_at' => $this->iso($u->last_seen_at),
            'email_verified' => (bool) $u->email_verified_at,
            'phone_verified' => (bool) $u->phone_verified_at,
            'kyc_status' => $u->kyc_status,
            'two_factor_enabled' => (bool) DB::table('user_security_profiles')->where('user_id', $id)->value('two_factor_enabled'),
            'wallet_pin_set' => (bool) $u->wallet_pin_set_at,
            'wallet_pin_failed_attempts' => (int) $u->wallet_pin_failed_attempts,
            'wallet_pin_locked_until' => $this->iso($u->wallet_pin_locked_until),
            'credits_balance' => (float) $u->credits,
            'wallet_balance_ugx' => (float) $u->ugx_balance,
            'capabilities' => DB::table('user_capabilities')->where('user_id', $id)
                ->get(['capability', 'status', 'granted_at', 'suspended_at', 'revoked_at'])
                ->map(fn ($c) => [
                    'capability' => $c->capability, 'status' => $c->status,
                    'granted_at' => $this->iso($c->granted_at), 'suspended_at' => $this->iso($c->suspended_at), 'revoked_at' => $this->iso($c->revoked_at),
                ])->all(),
        ];
    }

    private function money(int $id, ?string $profilePhone, bool $reveal): array
    {
        $bySource = DB::table('credit_transactions')->where('user_id', $id)
            ->groupBy('type', 'source')
            ->selectRaw('type, source, COUNT(*) AS n, SUM(amount) AS total, MIN(created_at) AS first_at, MAX(created_at) AS last_at')
            ->orderByDesc('total')->get();

        $earned = (float) $bySource->where('type', 'earned')->sum('total');
        $spent = (float) $bySource->where('type', 'spent')->sum('total');
        $cashout = (float) $bySource->where('source', 'wallet_cashout')->sum('total');

        $withdrawals = DB::table('payments')->where('user_id', $id)->where('payment_type', 'withdrawal')->orderBy('id')
            ->get(['id', 'created_at', 'amount', 'currency', 'status', 'phone_number', 'failure_reason', 'completed_at']);

        $payoutNumbers = $withdrawals->groupBy(fn ($w) => $this->phoneKey($w->phone_number) ?? 'unknown')
            ->map(function (Collection $rows, string $key) use ($id, $profilePhone, $reveal) {
                $completed = $rows->where('status', 'completed');

                return [
                    'phone' => $this->phone($rows->first()->phone_number, $reveal),
                    'withdrawals' => $rows->count(),
                    'total' => (float) $completed->sum('amount'),
                    'is_completed' => $completed->isNotEmpty(),
                    'matches_profile' => $profilePhone !== null && $key === $profilePhone,
                    'other_accounts' => $key === 'unknown' ? 0 : (int) DB::table('payments')
                        ->where('payment_type', 'withdrawal')->where('user_id', '<>', $id)
                        ->where('phone_number', 'like', '%'.$key)->distinct()->count('user_id'),
                ];
            })->values()->all();

        return [
            'earned_total' => $earned,
            'spent_total' => $spent,
            'cashout_total' => $cashout,
            'cashout_ratio' => $earned > 0 ? round($cashout / $earned, 3) : null,
            'by_source' => $bySource->map(fn ($r) => [
                'type' => $r->type, 'source' => $r->source, 'count' => (int) $r->n, 'total' => (float) $r->total,
                'first_at' => $this->iso($r->first_at), 'last_at' => $this->iso($r->last_at),
            ])->all(),
            'payments' => DB::table('payments')->where('user_id', $id)
                ->groupBy('payment_type', 'provider', 'status', 'currency')
                ->selectRaw('payment_type, provider, status, currency, COUNT(*) AS n, SUM(amount) AS total')
                ->get()->map(fn ($r) => [
                    'payment_type' => $r->payment_type, 'provider' => $r->provider, 'status' => $r->status,
                    'currency' => $r->currency, 'count' => (int) $r->n, 'total' => (float) $r->total,
                ])->all(),
            'withdrawals' => $withdrawals->map(fn ($w) => [
                'id' => $w->id, 'created_at' => $this->iso($w->created_at), 'amount' => (float) $w->amount, 'currency' => $w->currency,
                'status' => $w->status, 'phone' => $this->phone($w->phone_number, $reveal),
                'failure_reason' => $w->failure_reason, 'completed_at' => $this->iso($w->completed_at),
            ])->all(),
            'withdrawals_completed_total' => (float) $withdrawals->where('status', 'completed')->sum('amount'),
            'withdrawals_failed_count' => $withdrawals->where('status', 'failed')->count(),
            'payout_numbers' => $payoutNumbers,
            'daily' => DB::table('credit_transactions')->where('user_id', $id)->where('created_at', '>=', now()->subDays(90))
                ->groupBy(DB::raw('DATE(created_at)'))->orderBy(DB::raw('DATE(created_at)'))
                ->selectRaw("DATE(created_at) AS d, SUM(CASE WHEN type = 'earned' THEN amount ELSE 0 END) AS earned, SUM(CASE WHEN source = 'wallet_cashout' THEN amount ELSE 0 END) AS cashed_out")
                ->get()->map(fn ($r) => ['date' => $r->d, 'earned' => (float) $r->earned, 'cashed_out' => (float) $r->cashed_out])->all(),
        ];
    }

    private function kyc(int $id): array
    {
        $u = DB::table('users')->where('id', $id)->first(['kyc_status', 'kyc_submitted_at', 'kyc_verified_at', 'kyc_expires_at', 'kyc_rejection_reason', 'nin_number']);
        $docs = DB::table('kyc_documents')->where('user_id', $id)->orderBy('id')
            ->get(['id', 'document_type', 'status', 'file_name', 'created_at', 'verified_at', 'verified_by', 'rejection_reason']);
        $verifiers = DB::table('users')->whereIn('id', $docs->pluck('verified_by')->filter()->unique())
            ->get(['id', 'display_name', 'email'])->keyBy('id');

        $minutes = $u->kyc_submitted_at && $u->kyc_verified_at
            ? round(Carbon::parse($u->kyc_submitted_at)->diffInSeconds(Carbon::parse($u->kyc_verified_at), true) / 60, 1)
            : null;

        $firstWithdrawal = $u->kyc_verified_at
            ? DB::table('payments')->where('user_id', $id)->where('payment_type', 'withdrawal')
                ->where('created_at', '>=', $u->kyc_verified_at)->min('created_at')
            : null;

        return [
            'status' => $u->kyc_status,
            'submitted_at' => $this->iso($u->kyc_submitted_at),
            'verified_at' => $this->iso($u->kyc_verified_at),
            'expires_at' => $this->iso($u->kyc_expires_at),
            'rejection_reason' => $u->kyc_rejection_reason,
            'has_identity_record' => filled($u->nin_number),
            'minutes_to_approval' => $minutes,
            'first_withdrawal_minutes_after_verified' => $firstWithdrawal
                ? round(Carbon::parse($u->kyc_verified_at)->diffInSeconds(Carbon::parse($firstWithdrawal), true) / 60, 1)
                : null,
            'documents' => $docs->map(fn ($d) => [
                'id' => $d->id, 'type' => $d->document_type, 'status' => $d->status, 'file_name' => $d->file_name,
                'submitted_at' => $this->iso($d->created_at), 'verified_at' => $this->iso($d->verified_at),
                'verified_by' => $d->verified_by,
                'verified_by_name' => $d->verified_by ? ($verifiers[$d->verified_by]->display_name ?? $verifiers[$d->verified_by]->email ?? null) : null,
                'rejection_reason' => $d->rejection_reason,
            ])->all(),
        ];
    }

    private function contributions(int $id): array
    {
        if (! Schema::hasTable('contribution_submissions') || ! Schema::hasTable('contributor_profiles')) {
            return ['available' => false];
        }

        $profile = DB::table('contributor_profiles')->where('user_id', $id)->first([
            'tier', 'gold_attempts', 'gold_passed', 'submissions_total', 'submissions_accepted',
            'validations_total', 'credits_earned_total', 'is_suspended', 'suspended_reason', 'consented_at',
        ]);

        if (! $profile) {
            return ['available' => false];
        }

        $stats = DB::table('contribution_submissions')->where('user_id', $id)->whereNull('deleted_at')
            ->selectRaw('COUNT(*) AS n, COUNT(DISTINCT normalized_text) AS uniq, AVG(CHAR_LENGTH(raw_text)) AS avg_len, AVG(agreement_score) AS agreement, MIN(created_at) AS first_at, MAX(created_at) AS last_at')
            ->first();

        return [
            'available' => true,
            'tier' => $profile->tier,
            'submissions' => (int) $stats->n,
            'unique_texts' => (int) $stats->uniq,
            'accepted' => (int) $profile->submissions_accepted,
            'avg_length' => round((float) $stats->avg_len, 1),
            'avg_agreement_score' => $stats->agreement !== null ? round((float) $stats->agreement, 1) : null,
            'gold_attempts' => (int) $profile->gold_attempts,
            'gold_passed' => (int) $profile->gold_passed,
            'validations_total' => (int) $profile->validations_total,
            'validations_paid' => (int) DB::table('credit_transactions')->where('user_id', $id)->where('source', 'contribution_validation')->count(),
            'credits_earned_total' => (float) $profile->credits_earned_total,
            'is_suspended' => (bool) $profile->is_suspended,
            'suspended_reason' => $profile->suspended_reason,
            'first_at' => $this->iso($stats->first_at),
            'last_at' => $this->iso($stats->last_at),
            'daily' => DB::table('contribution_submissions')->where('user_id', $id)->whereNull('deleted_at')
                ->groupBy(DB::raw('DATE(created_at)'))->orderBy(DB::raw('DATE(created_at)'))
                ->selectRaw('DATE(created_at) AS d, COUNT(*) AS n, MIN(created_at) AS t1, MAX(created_at) AS t2')
                ->get()->map(fn ($r) => ['date' => $r->d, 'count' => (int) $r->n, 'first_at' => $this->iso($r->t1), 'last_at' => $this->iso($r->t2)])->all(),
        ];
    }

    private function listening(int $id): array
    {
        $stats = DB::table('play_histories')->where('user_id', $id)
            ->selectRaw('COUNT(*) AS plays, COUNT(DISTINCT song_id) AS songs, COUNT(DISTINCT DATE(played_at)) AS days, SUM(COALESCE(duration_listened, duration_played_seconds, 0)) AS secs, AVG(completion_percentage) AS comp, SUM(CASE WHEN skipped = 1 THEN 1 ELSE 0 END) AS skipped, MIN(played_at) AS first_at, MAX(played_at) AS last_at')
            ->first();

        $listenEarn = DB::table('credit_transactions')->where('user_id', $id)->where('source', 'listen_earn')->orderBy('id')
            ->get(['created_at', 'amount', 'description'])
            ->map(fn ($r) => [
                'date' => substr((string) $r->created_at, 0, 10),
                'amount' => (float) $r->amount,
                'seconds' => preg_match('/\((\d+)s listened\)/', (string) $r->description, $m) ? (int) $m[1] : null,
            ])->all();

        return [
            'plays' => (int) $stats->plays,
            'distinct_songs' => (int) $stats->songs,
            'active_days' => (int) $stats->days,
            'seconds_listened' => (int) $stats->secs,
            'avg_completion' => $stats->comp !== null ? round((float) $stats->comp, 1) : null,
            'skipped' => (int) $stats->skipped,
            'first_at' => $this->iso($stats->first_at),
            'last_at' => $this->iso($stats->last_at),
            'top_songs' => DB::table('play_histories')->leftJoin('songs', 'songs.id', '=', 'play_histories.song_id')
                ->where('play_histories.user_id', $id)->groupBy('play_histories.song_id', 'songs.title')
                ->orderByDesc(DB::raw('COUNT(*)'))->limit(10)
                ->get(['play_histories.song_id', 'songs.title', DB::raw('COUNT(*) AS plays')])
                ->map(fn ($r) => ['song_id' => $r->song_id, 'title' => $r->title, 'plays' => (int) $r->plays])->all(),
            'daily' => DB::table('play_histories')->where('user_id', $id)->where('played_at', '>=', now()->subDays(60))
                ->groupBy(DB::raw('DATE(played_at)'))->orderBy(DB::raw('DATE(played_at)'))
                ->selectRaw('DATE(played_at) AS d, COUNT(*) AS n')->get()->map(fn ($r) => ['date' => $r->d, 'plays' => (int) $r->n])->all(),
            'listen_earn' => $listenEarn,
        ];
    }

    private function network(int $id, bool $reveal): array
    {
        $referrerId = DB::table('user_referrals')->where('user_id', $id)->value('referrer_id');
        $refereeIds = DB::table('user_referrals')->where('referrer_id', $id)->pluck('user_id');

        $plays = DB::table('play_histories')->whereIn('user_id', $refereeIds)->groupBy('user_id')->selectRaw('user_id, COUNT(*) AS n')->pluck('n', 'user_id');
        $credits = DB::table('credit_transactions')->whereIn('user_id', $refereeIds)->groupBy('user_id')->selectRaw('user_id, COUNT(*) AS n')->pluck('n', 'user_id');

        $transfers = DB::table('credit_transactions')->where('user_id', $id)->whereIn('source', ['transfer_in', 'transfer_out'])->orderBy('id')
            ->get(['created_at', 'type', 'source', 'amount', 'description', 'referenceable_id']);
        $counterparts = DB::table('users')->whereIn('id', $transfers->pluck('referenceable_id')->filter()->unique())
            ->get(['id', 'display_name', 'email', 'role'])->keyBy('id');

        return [
            'referrer' => $referrerId ? $this->person(DB::table('users')->where('id', $referrerId)->first(['id', 'display_name', 'email', 'created_at', 'last_login_at']), $reveal) : null,
            'referees' => DB::table('users')->whereIn('id', $refereeIds)->get(['id', 'display_name', 'email', 'created_at', 'last_login_at'])
                ->map(fn ($u) => [
                    ...$this->person($u, $reveal),
                    'created_at' => $this->iso($u->created_at),
                    'last_login_at' => $this->iso($u->last_login_at),
                    'plays' => (int) ($plays[$u->id] ?? 0),
                    'credit_transactions' => (int) ($credits[$u->id] ?? 0),
                ])->all(),
            'transfers' => $transfers->map(fn ($t) => [
                'at' => $this->iso($t->created_at), 'direction' => $t->source === 'transfer_in' ? 'in' : 'out',
                'amount' => (float) $t->amount, 'note' => $t->description,
                'counterpart' => isset($counterparts[$t->referenceable_id])
                    ? [...$this->person($counterparts[$t->referenceable_id], $reveal), 'role' => $counterparts[$t->referenceable_id]->role]
                    : null,
            ])->all(),
        ];
    }

    private function security(int $id): array
    {
        $events = DB::table('observability_events')->where('actor_id', $id)
            ->groupBy('category', 'outcome', 'severity')
            ->selectRaw('category, outcome, severity, COUNT(*) AS n, MAX(risk_score) AS max_risk, MIN(occurred_at) AS first_at, MAX(occurred_at) AS last_at')
            ->get();

        $routes = fn (string $category, ?string $outcome = null) => DB::table('observability_events')->where('actor_id', $id)->where('category', $category)
            ->when($outcome, fn ($q) => $q->where('outcome', $outcome))
            ->groupBy('target_method', 'target_route', 'title')
            ->selectRaw('target_method, target_route, title, COUNT(*) AS n, MIN(occurred_at) AS first_at, MAX(occurred_at) AS last_at')
            ->orderByDesc('n')->limit(15)->get()
            ->map(fn ($r) => [
                'method' => $r->target_method, 'route' => $r->target_route, 'title' => $r->title, 'count' => (int) $r->n,
                'first_at' => $this->iso($r->first_at), 'last_at' => $this->iso($r->last_at),
            ])->all();

        $api = DB::table('api_usage_logs')->where('user_id', $id)
            ->selectRaw('COUNT(*) AS n, COUNT(DISTINCT ip_address) AS ips, MIN(requested_at) AS first_at, MAX(requested_at) AS last_at')->first();

        $endpoints = DB::table('api_usage_logs')->where('user_id', $id)
            ->groupBy('method', 'endpoint', 'status_code')->selectRaw('method, endpoint, status_code, COUNT(*) AS n')->get()
            ->groupBy(fn ($r) => $r->method.' '.preg_replace('/\d+/', '{n}', $r->endpoint).' '.$r->status_code)
            ->map(fn (Collection $g) => ['method' => $g[0]->method, 'endpoint' => preg_replace('/\d+/', '{n}', $g[0]->endpoint), 'status' => (int) $g[0]->status_code, 'count' => (int) $g->sum('n')])
            ->sortByDesc('count')->values();

        $login = $events->where('category', 'login');

        return [
            'events' => $events->map(fn ($e) => [
                'category' => $e->category, 'outcome' => $e->outcome, 'severity' => $e->severity, 'count' => (int) $e->n,
                'max_risk' => (int) $e->max_risk, 'first_at' => $this->iso($e->first_at), 'last_at' => $this->iso($e->last_at),
            ])->all(),
            'blocked_count' => (int) $events->where('category', 'access')->where('outcome', 'blocked')->sum('n'),
            'blocked_routes' => $routes('access', 'blocked'),
            'rate_limited_count' => (int) $events->where('category', 'rate_limit')->sum('n'),
            'rate_limited_routes' => $routes('rate_limit'),
            'logins' => ['count' => (int) $login->sum('n'), 'first_at' => $this->iso($login->min('first_at')), 'last_at' => $this->iso($login->max('last_at'))],
            'api' => [
                'total_calls' => (int) $api->n,
                'distinct_ips' => (int) $api->ips,
                'first_at' => $this->iso($api->first_at),
                'last_at' => $this->iso($api->last_at),
                'top_endpoints' => $endpoints->where('status', 200)->take(15)->values()->all(),
                'non_200' => $endpoints->where('status', '!=', 200)->take(15)->values()->all(),
                'daily' => DB::table('api_usage_logs')->where('user_id', $id)->where('requested_at', '>=', now()->subDays(60))
                    ->groupBy(DB::raw('DATE(requested_at)'))->orderBy(DB::raw('DATE(requested_at)'))
                    ->selectRaw('DATE(requested_at) AS d, COUNT(*) AS n')->get()->map(fn ($r) => ['date' => $r->d, 'calls' => (int) $r->n])->all(),
            ],
        ];
    }

    private function person(?object $u, bool $reveal): array
    {
        return $u ? ['id' => $u->id, 'name' => $u->display_name, 'email' => $this->email($u->email, $reveal)] : [];
    }

    private function iso(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }

    /** Last 9 digits, so 0768788877 and 256768788877 compare equal. */
    private function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 7 ? substr($digits, -9) : null;
    }

    private function phone(?string $phone, bool $reveal): ?string
    {
        if (blank($phone)) {
            return null;
        }

        return $reveal ? $phone : substr($phone, 0, 4).str_repeat('•', max(strlen($phone) - 7, 0)).substr($phone, -3);
    }

    private function email(?string $email, bool $reveal): ?string
    {
        if (blank($email) || $reveal || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 2).str_repeat('•', max(strlen($local) - 2, 1)).'@'.$domain;
    }
}
