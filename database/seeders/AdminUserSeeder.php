<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * The credentials for the initial administrator account.
     *
     * The password is expected to be changed on first login.
     */
    public const string EMAIL = 'admin@admin.com';

    public const string PASSWORD = 'changethispassword';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Admin',
                'password' => self::PASSWORD,
            ],
        );
    }
}
