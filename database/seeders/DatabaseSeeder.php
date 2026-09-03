<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $superAdminRole = Role::updateOrCreate(['name' => 'super_admin'], [
            'label' => 'Super Admin',
            'description' => 'Akses penuh: kelola user, dashboard, semua fitur GIS',
        ]);

        $adminRole = Role::updateOrCreate(['name' => 'admin'], [
            'label' => 'Admin',
            'description' => 'Kelola lokasi, impor/ekspor, jarak, radius',
        ]);

        $userRole = Role::updateOrCreate(['name' => 'user'], [
            'label' => 'User',
            'description' => 'Hanya bisa melihat peta',
        ]);

        User::updateOrCreate(
            ['email' => 'superadmin@admin.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role_id' => $superAdminRole->id,
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'role_id' => $adminRole->id,
            ]
        );

        User::updateOrCreate(
            ['email' => 'user@user.com'],
            [
                'name' => 'User Biasa',
                'password' => Hash::make('password'),
                'role_id' => $userRole->id,
            ]
        );

        $this->call([
            CategorySeeder::class,
            LocationSeeder::class,
        ]);
    }
}
