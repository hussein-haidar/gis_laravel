<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * URL avatar dari Google (lh3.googleusercontent.com) bisa lebih dari 255
     * karakter karena token gambarnya panjang. Kolom string(255) memotongnya
     * dan MySQL menolak dengan "Data too long for column 'avatar'".
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('avatar')->nullable()->change();
        });
    }

    /**
     * Kembalikan ke string(255). Nilai yang sudah terlanjur panjang akan
     * terpotong, jadi jalankan hanya kalau kolomnya memang dikosongkan dulu.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->change();
        });
    }
};