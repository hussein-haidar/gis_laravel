<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed pengaturan tampilan situs untuk halaman "Kelola Web".
 *
 * Dipisah dari `2026_10_01_000001_seed_default_settings.php` karena berkas itu
 * sudah pernah jalan di server mana pun. Menambah baris baru di sana tidak
 * akan dieksekusi pada instalasi yang sudah melakukan migrate.
 *
 * Prinsip sama seperti seed aslinya: HANYA menyisipkan baris yang belum ada,
 * jadi nilai yang sudah diisi Super Admin tidak pernah ditimpa.
 */
return new class extends Migration
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function defaults(): array
    {
        return [
            [
                'key' => 'site_title',
                // Ikuti APP_NAME supaya form Kelola Web langsung terisi nama
                // situs yang benar-benar aktif, bukan teks placeholder.
                'value' => (string) (config('app.name') ?: 'GIS Laravel'),
                'type' => 'string',
                'group' => 'web',
                'label' => 'Judul Situs',
                'description' => 'Judul yang tampil di tab browser dan header situs.',
                'is_secret' => false,
            ],
            [
                'key' => 'site_description',
                'value' => '',
                'type' => 'text',
                'group' => 'web',
                'label' => 'Deskripsi Situs',
                'description' => 'Deskripsi singkat situs, dipakai untuk meta description.',
                'is_secret' => false,
            ],
            [
                'key' => 'banner_enabled',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'web',
                'label' => 'Tampilkan Banner',
                'description' => 'Tampilkan banner menyapa di halaman depan.',
                'is_secret' => false,
            ],
            [
                'key' => 'banner_title',
                'value' => '',
                'type' => 'string',
                'group' => 'web',
                'label' => 'Judul Banner',
                'description' => 'Judul besar pada banner.',
                'is_secret' => false,
            ],
            [
                'key' => 'banner_text',
                'value' => '',
                'type' => 'text',
                'group' => 'web',
                'label' => 'Isi Banner',
                'description' => 'Keterangan singkat di bawah judul banner.',
                'is_secret' => false,
            ],
            [
                'key' => 'announcement_enabled',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'web',
                'label' => 'Tampilkan Pengumuman',
                'description' => 'Tampilkan kotak pengumuman di halaman depan.',
                'is_secret' => false,
            ],
            [
                'key' => 'announcement_text',
                'value' => '',
                'type' => 'text',
                'group' => 'web',
                'label' => 'Isi Pengumuman',
                'description' => 'Teks pengumuman, mis. info maintenance atau hari libur nasional.',
                'is_secret' => false,
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $now = now();

        foreach ($this->defaults() as $row) {
            $exists = DB::table('settings')->where('key', $row['key'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('settings')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach ($this->defaults() as $row) {
            DB::table('settings')
                ->where('key', $row['key'])
                ->where('value', $row['value'])
                ->where('type', $row['type'])
                ->where('group', $row['group'])
                ->delete();
        }
    }
};
