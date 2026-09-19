<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\TestAlert;
use App\Services\SendPushMessage;
use Illuminate\Console\Command;

/**
 * Sends one harmless notification to one account.
 *
 * The only way to find out whether a phone actually buzzes. See TestAlert for
 * why that cannot be settled by the test suite.
 */
class SendTestNotification extends Command
{
    protected $signature = 'notifications:test {identifier : Phone number or email} {--message=}';

    protected $description = 'Send a test notification to one account';

    public function handle(SendPushMessage $push): int
    {
        $user = User::findByIdentifier($this->argument('identifier'));

        if (! $user) {
            $this->error('No account matches '.$this->argument('identifier').'.');

            return self::FAILURE;
        }

        $user->notify(new TestAlert(
            $this->option('message') ?: 'This is a test. Nothing has happened to your order.',
        ));

        $this->info("Sent to {$user->name} ({$user->phone}).");

        // Said plainly, because "it did not buzz" has two very different causes
        // and only one of them is worth debugging.
        $devices = $user->pushSubscriptions()->count();

        match (true) {
            ! $push->configured() => $this->warn('Push is off: no VAPID keys. The in-app bell still has it.'),
            $devices === 0 => $this->warn('No devices subscribed, so nothing was pushed. The in-app bell still has it.'),
            default => $this->line("Pushed to {$devices} device".($devices === 1 ? '' : 's').'.'),
        };

        return self::SUCCESS;
    }
}
