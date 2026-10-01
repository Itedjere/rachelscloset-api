<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\NigerianPhone;
use App\Rules\Pin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Makes an admin account, on a server, with a PIN nobody else has seen.
 *
 * Admins cannot sign themselves up (User::SELF_SIGNUP_ROLES), and the demo
 * admin the seeder makes locally has a PIN written in CLAUDE.md -- so a live
 * server needs another way to get its first admin. This is it.
 *
 * The PIN is only ever asked for, hidden, and typed twice. It is never an
 * option on the command line, because a command line is kept in shell
 * history, in `ps` output, and in whatever transcript the terminal is part
 * of. Which also means this has to be run by a person, in a terminal.
 *
 * It refuses a phone number that already has an account rather than turning
 * that account into an admin. Promoting somebody is a decision about a
 * person; this command is for making a new, empty admin account.
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create {--name= : Their name} {--phone= : Their phone number}';

    protected $description = 'Create an admin account (asks for the PIN, hidden)';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this in a terminal: the PIN is typed, hidden, never passed on the command line.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Their name')));
        $rawPhone = (string) ($this->option('phone') ?: $this->ask('Their phone number (this is how they sign in)'));

        // Normalised before the unique check, as at signup -- otherwise one
        // number written two ways would get past it as two different numbers.
        $phone = NigerianPhone::normalise($rawPhone) ?? $rawPhone;

        $pin = (string) $this->secret('Choose a 6-digit PIN (hidden)');
        $confirm = (string) $this->secret('Type the PIN again');

        $validator = Validator::make(
            ['name' => $name, 'phone' => $phone, 'pin' => $pin, 'pin_confirmation' => $confirm],
            [
                'name' => ['required', 'string', 'max:120'],
                'phone' => ['required', 'string', new NigerianPhone, 'unique:users,phone'],
                'pin' => ['required', 'confirmed', new Pin($phone)],
            ],
            [
                'phone.unique' => 'That phone number already has an account. This command only makes new ones.',
                'pin.confirmed' => 'The two PINs are not the same.',
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            $this->line('Nothing was created.');

            return self::FAILURE;
        }

        // The `hashed` cast on User turns the PIN into a bcrypt hash on save.
        $admin = User::create([
            'name' => $name,
            'phone' => $phone,
            'password' => $pin,
            'role' => User::ROLE_ADMIN,
        ]);

        $this->info("Admin created: {$admin->name}, signs in with {$admin->phone}.");

        return self::SUCCESS;
    }
}
