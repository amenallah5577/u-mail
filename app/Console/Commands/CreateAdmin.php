<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountCredentialService;
use App\Services\PublicEmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature = 'utica:create-admin {email} {--name=UTICA Administrator} {--password=}';

    protected $description = 'Create or update the initial active UTICA administrator';

    public function handle(AccountCredentialService $credentials, PublicEmailService $publicEmails): int
    {
        $password = $this->option('password') ?: $this->secret('Password');
        if (! $password || strlen($password) < 12 || ! preg_match('/[a-z]/', $password) || ! preg_match('/[A-Z]/', $password) || ! preg_match('/\d/', $password) || ! preg_match('/[^A-Za-z0-9]/', $password)) {
            $this->error('Password must contain at least 12 characters with upper and lower case letters, a number, and a symbol.');

            return self::FAILURE;
        }

        $email = strtolower($this->argument('email'));
        $existing = User::where('email', $email)->first();
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $this->option('name'),
                'public_email' => $existing?->public_email ?: $publicEmails->generate($this->option('name'), $existing),
                'password' => Hash::make($password),
                'role' => 'admin',
                'status' => 'active',
                'activated_at' => now(),
            ],
        );
        $credentials->store($user, $password);
        $this->info("Administrator ready: {$user->public_email}");

        return self::SUCCESS;
    }
}
