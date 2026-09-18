<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateOwner extends Command
{
    protected $signature = 'crm:create-owner';

    protected $description = 'Create the initial owner without default production credentials';

    public function handle(): int
    {
        if (User::where('role', 'owner')->exists()) {
            $this->error('An owner already exists.');

            return self::FAILURE;
        }
        $data = ['name' => $this->ask('Owner name'), 'email' => strtolower($this->ask('Email')), 'password' => $this->secret('Password (at least 12 characters)')];
        $validator = Validator::make($data, ['name' => 'required|max:255', 'email' => 'required|email|unique:users,email', 'password' => 'required|min:12|max:128']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

return self::FAILURE;
        }
        User::create($data + ['role' => 'owner', 'active' => true]);
        $this->info('Owner created.');

        return self::SUCCESS;
    }
}
