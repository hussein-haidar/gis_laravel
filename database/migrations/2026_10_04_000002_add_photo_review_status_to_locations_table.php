<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status persetujuan foto lokasi oleh admin.
     *
     * Semua foto hasil fetch masuk antrean "pending" dan baru tampil di peta
     * setelah disetujui. Foto yang ditolak akan dihapus bersama lokasinya,
     * jadi tidak ada lokasi yang memakai foto bukan gambarnya.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('photo_review_status')
                ->nullable()
                ->after('photo_fetched_at')
                ->index();
            $table->timestamp('photo_reviewed_at')->nullable()->after('photo_review_status');
            $table->foreignId('photo_reviewed_by')
                ->nullable()
                ->after('photo_reviewed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('photo_reviewed_by');
            $table->dropColumn(['photo_review_status', 'photo_reviewed_at']);
        });
    }
};
