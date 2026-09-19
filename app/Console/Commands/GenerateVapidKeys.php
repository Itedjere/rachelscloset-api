<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * Generates the key pair that signs push messages.
 *
 * Run once per environment. The public key is handed to browsers so their push
 * service knows who is allowed to send; the private key signs each request and
 * must never leave the server.
 *
 * Regenerating invalidates every subscription already stored -- every phone
 * would go quiet with nothing to show for it -- so this refuses to overwrite.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid';

    protected $description = 'Generate a VAPID key pair for web push notifications';

    public function handle(): int
    {
        if (config('services.push.public_key')) {
            $this->warn('VAPID keys are already set.');
            $this->line('Replacing them would silently break every device already subscribed.');
            $this->line('Clear VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY from .env first if you mean it.');

            return self::FAILURE;
        }

        try {
            $keys = VAPID::createVapidKeys();
        } catch (Throwable $exception) {
            $this->error('Could not generate the keys: '.$exception->getMessage());
            // The usual cause on Windows, where PHP ships without a config file.
            $this->line('If that mentions a configuration file, OpenSSL cannot find openssl.cnf.');
            $this->line('Set OPENSSL_CONF to its path and run this again.');

            return self::FAILURE;
        }

        $this->info('Add these to your .env:');
        $this->newLine();
        $this->line('VAPID_SUBJECT=mailto:support@rachelscloset.com.ng');
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->comment('Keep the private key out of version control.');

        return self::SUCCESS;
    }
}
