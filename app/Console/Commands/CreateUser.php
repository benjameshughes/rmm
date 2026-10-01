<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

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
        $name = (string) ($this->option('name') ?: text(label: 'Name', required: true));
        $email = (string) ($this->option('email') ?: text(label: 'Email', required: true));
        $password = (string) ($this->option('password') ?: password(label: 'Password', required: true));

        $input = [
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ];

        $validator = Validator::make($input, $creator->rules());

        if ($validator->fails()) {
            collect($validator->errors()->all())->each(fn (string $error) => $this->components->error($error));

            return self::FAILURE;
        }

        $user = $creator->create($input);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->components->info("User [{$user->email}] created.");

        return self::SUCCESS;
    }
}
