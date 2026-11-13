<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateSuperadmin extends Command
{
    protected $signature = 'audira:create-superadmin {email} {name} {--password= : Omit to be prompted securely}';

    protected $description = 'Create a platform Superadmin account';

    public function handle(ActivityLogger $logger): int
    {
        $password = $this->option('password') ?? $this->secret('Password (min 12 chars, mixed case, number, symbol)');
        $data = ['email' => strtolower($this->argument('email')), 'name' => $this->argument('name'), 'password' => $password];

        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'unique:users,email'],
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', Password::defaults()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([...$data, 'type' => UserType::Platform, 'status' => UserStatus::Active]);
        $logger->log('platform.superadmin_created', "Superadmin {$user->email} created", $user, [], null, null);
        $this->info("Superadmin {$user->email} created.");

        return self::SUCCESS;
    }
}
