<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // Judul berkas asal di Wikimedia/Openverse, mis.
            // "File:Candi Prambanan 2019.jpg". Tanpa ini sumber foto tidak
            // bisa diaudit: isi gambar tidak pernah membuktikan nama tempatnya.
            $table->string('photo_source_title')->nullable()->after('photo');
            $table->string('photo_source_url', 512)->nullable()->after('photo_source_title');
            $table->string('photo_source_provider', 32)->nullable()->after('photo_source_url');
            $table->timestamp('photo_fetched_at')->nullable()->after('photo_source_provider');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn([
                'photo_source_title',
                'photo_source_url',
                'photo_source_provider',
                'photo_fetched_at',
            ]);
        });
    }
};
