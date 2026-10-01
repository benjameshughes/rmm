<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

final class CreateUser extends Command
{
    protected $signature = 'users:create
                            {--name= : The name of the user}
                            {--email= : The email address of the user}
                            {--password= : The password for the user}';

    protected $description = 'Create a new admin user';

    public function handle(CreateNewUser $creator): int
    {
        $name = $this->option('name') ?: text(label: 'Name', required: true);
        $email = $this->option('email') ?: text(label: 'Email', required: true);
        $password = $this->option('password') ?: password(label: 'Password', required: true);

        try {
            $user = $creator->create([
                'name' => (string) $name,
                'email' => (string) $email,
                'password' => (string) $password,
                'password_confirmation' => (string) $password,
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->components->info("User [{$user->email}] created.");

        return self::SUCCESS;
    }
}
