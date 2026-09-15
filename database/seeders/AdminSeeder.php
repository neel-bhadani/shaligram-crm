<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The one account a fresh install needs: an admin to sign in and create
 * everybody else.
 *
 * Keyed on email, so a second run finds the account and leaves it exactly as
 * it is — including a password the admin has since changed.
 *
 * Unguarded because `approval_status` is kept out of User::$fillable on
 * purpose. Set explicitly rather than trusted to the column default: a pending
 * admin cannot sign in, and on an empty database there is nobody to approve
 * them. The password goes in plain; the model's `hashed` cast hashes it.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::unguarded(fn () => User::firstOrCreate(
            ['email' => 'admin1@gmail.com'],
            [
                'first_name' => 'Sagar',
                'last_name' => 'Moradia',
                'mobile_number' => '8866834847',
                'password' => '123456789',
                'role' => 'admin',
                'is_active' => true,
                'approval_status' => 'approved',
            ],
        ));
    }
}
