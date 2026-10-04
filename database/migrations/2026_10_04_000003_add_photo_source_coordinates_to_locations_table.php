<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Koordinat yang diambil dari berkas sumber di Wikimedia Commons.
     *
     * Ini bukti jauh lebih kuat daripada judul: foto yang memang diambil di
     * lokasi itu punya koordinat yang berdekatan. Dipakai untuk:
     *  - menyetujui otomatis foto yang koordinatnya dekat (<=800 m)
     *  - membuang foto yang koordinatnya jauh (>5 km) karena milik tempat lain
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->decimal('photo_source_lat', 10, 7)->nullable()->after('photo_source_provider');
            $table->decimal('photo_source_lon', 10, 7)->nullable()->after('photo_source_lat');
            $table->unsignedInteger('photo_source_distance_m')->nullable()->after('photo_source_lon');
            $table->string('photo_review_note')->nullable()->after('photo_reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn([
                'photo_source_lat',
                'photo_source_lon',
                'photo_source_distance_m',
                'photo_review_note',
            ]);
        });
    }
};
