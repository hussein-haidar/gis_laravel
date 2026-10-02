<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel `settings` sebelumnya dibuat manual di MySQL dan tidak punya migration,
 * sehingga `php artisan migrate` di database baru (atau di test sqlite)
 * tidak pernah membuatnya. Migration ini menutup celah tersebut.
 *
 *Dijaga `hasTable()` supaya aman dijalankan di database yang sudah punya
 * tabel ini beserta data yang sudah diisi admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            return;
        }

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->string('group');
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
