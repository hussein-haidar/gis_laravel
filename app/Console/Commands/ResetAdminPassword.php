<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password
                            {password? : Password baru (opsional, bila kosong akan ditanyakan interaktif)}
                            {--email=admin@admin.com : Email akun admin}';

    protected $description = 'Mengubah password akun admin dengan hash bcrypt yang benar';

    public function handle(): int
    {
        $email = $this->option('email');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("Akun dengan email '{$email}' tidak ditemukan.");

            return self::FAILURE;
        }

        $password = $this->argument('password');

        if (! $password) {
            $password = $this->secret('Password baru untuk ' . $user->email);
        }

        if (strlen((string) $password) < 8) {
            $this->error('Password minimal 8 karakter.');

            return self::FAILURE;
        }

        $user->password = Hash::make($password);
        $user->save();

        $this->info("Password untuk {$user->email} berhasil diganti.");

        return self::SUCCESS;
    }
}
