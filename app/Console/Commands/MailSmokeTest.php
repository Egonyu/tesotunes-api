<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Prove that mail actually leaves the server.
 *
 * Ticket confirmations were failing silently for months: the notification was
 * dispatched, the job failed in the queue, and nothing surfaced until someone
 * read failed_jobs. There was no way to check delivery short of making a real
 * purchase, so a mail misconfiguration could sit unnoticed indefinitely.
 *
 * This sends one message through the configured mailer and prints the transport
 * it used, so a change of provider can be verified in one step.
 */
class MailSmokeTest extends Command
{
    protected $signature = 'mail:smoke
                            {recipient : Address to send the test message to}
                            {--queue : Send through the queue instead of immediately, to also prove the worker delivers}';

    protected $description = 'Send a test email and report the transport actually used';

    public function handle(): int
    {
        $recipient = (string) $this->argument('recipient');

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error("Not a valid email address: {$recipient}");

            return self::FAILURE;
        }

        $mailer = config('mail.default');
        $host = config("mail.mailers.{$mailer}.host");
        $port = config("mail.mailers.{$mailer}.port");
        $username = config("mail.mailers.{$mailer}.username");
        $from = config('mail.from.address');

        $this->line('Sending with:');
        $this->line("  mailer   {$mailer}");
        $this->line('  host     '.($host ?: '(n/a)').($port ? ":{$port}" : ''));
        $this->line('  username '.($username ?: '(none)'));
        $this->line("  from     {$from}");
        $this->line("  to       {$recipient}");
        $this->newLine();

        $sentAt = now()->toDateTimeString();

        try {
            Mail::raw(
                "TesoTunes mail smoke test.\n\n"
                ."Sent {$sentAt} via {$host} as {$from}.\n"
                .'If you are reading this, transactional mail is leaving the server.',
                function ($message) use ($recipient) {
                    $message->to($recipient)->subject('TesoTunes mail smoke test');
                }
            );
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Send failed: '.$e->getMessage());
            $this->newLine();
            $this->line('A 550 "unusual sending activity" here means the account is a');
            $this->line('mailbox being used for automated mail. Transactional sending');
            $this->line('belongs on a transactional host, not a mailbox account.');

            return self::FAILURE;
        }

        $this->info('Accepted by the mail host.');
        $this->line('Delivery is still the provider\'s to complete — check the inbox to confirm.');

        return self::SUCCESS;
    }
}
