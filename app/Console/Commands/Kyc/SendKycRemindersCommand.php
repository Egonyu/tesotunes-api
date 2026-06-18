<?php

namespace App\Console\Commands\Kyc;

use App\Enums\KycStatus;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Nudges users with incomplete identity verification (KYC) to finish, as an
 * in-app notification (the bell). KYC is never a wall to join — it's a journey
 * — so this gently reminds the backlog and new accounts to complete it before
 * they hit a withdrawal/payout gate. Frequency-capped per user.
 */
class SendKycRemindersCommand extends Command
{
    protected $signature = 'kyc:remind
        {--days=14 : Minimum days since this user was last reminded}
        {--grace=1 : Skip accounts younger than this many days}
        {--limit=1000 : Max users to nudge in one run}';

    protected $description = 'Remind users with incomplete KYC to finish verifying their identity.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $grace = max(0, (int) $this->option('grace'));
        $limit = max(1, (int) $this->option('limit'));

        $cutoff = now()->subDays($days);

        $recentlyReminded = Notification::query()
            ->where('type', 'kyc_reminder')
            ->where('created_at', '>=', $cutoff)
            ->distinct()
            ->pluck('user_id');

        $incomplete = [
            KycStatus::None->value,
            KycStatus::Partial->value,
            KycStatus::Rejected->value,
            KycStatus::Expired->value,
        ];

        $users = User::query()
            ->where('is_active', true)
            ->whereIn('kyc_status', $incomplete)
            ->where('created_at', '<=', now()->subDays($grace))
            ->whereNotIn('id', $recentlyReminded)
            ->limit($limit)
            ->get(['id']);

        if ($users->isEmpty()) {
            $this->info('No users need a KYC reminder right now.');

            return self::SUCCESS;
        }

        Notification::createBatchForUsers(
            userIds: $users->pluck('id')->all(),
            type: 'kyc_reminder',
            title: 'Finish verifying your identity',
            message: 'Complete your KYC to unlock withdrawals, payouts and seller payments. It only takes a few minutes.',
            data: ['module' => 'identity'],
            actionUrl: '/verify',
            category: 'kyc',
        );

        $this->info("Sent KYC reminders to {$users->count()} user(s).");

        return self::SUCCESS;
    }
}
