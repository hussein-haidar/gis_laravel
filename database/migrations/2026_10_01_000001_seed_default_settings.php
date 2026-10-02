<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Isi tabel `settings` dengan seluruh konfigurasi default aplikasi.
 *
 * Aplikasi ini membaca konfigurasi lewat `Setting::getValue()`. Tanpa baris
 * default, `php artisan migrate` di server baru menghasilkan aplikasi yang
 * sinkronisasi GIS, routing, traffic, dan asisten AI-nya diam-diam mati.
 *
 * Prinsip:
 * - HANYA menyisipkan baris yang belum ada. Nilai yang sudah diisi admin
 *   (termasuk secret terenkripsi) tidak pernah ditimpa atau di-dekripsi.
 * - Nilai secret default SELALU kosong. Kunci asli tidak pernah ikut repo.
 */
return new class extends Migration
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function defaults(): array
    {
        return [
            // ── Sumber data GIS ──────────────────────────────────────────────
            [
                'key' => 'gis_api_url',
                'value' => 'https://raw.githubusercontent.com/eppofahmi/geojson-indonesia/master/provinsi/all_maps_state_indo.geojson',
                'type' => 'string',
                'group' => 'gis',
                'label' => 'GIS Sync API URL',
                'description' => 'Endpoint GeoJSON untuk sinkronisasi data lokasi.',
                'is_secret' => false,
            ],
            [
                'key' => 'gis_api_key',
                'value' => '',
                'type' => 'string',
                'group' => 'gis',
                'label' => 'GIS API Key',
                'description' => 'API key opsional untuk endpoint GeoJSON. Kosongkan jika endpoint-nya publik.',
                'is_secret' => true,
            ],
            [
                'key' => 'gis_api_timeout',
                'value' => '30',
                'type' => 'integer',
                'group' => 'gis',
                'label' => 'GIS API Timeout (detik)',
                'description' => 'Batas waktu menunggu unduhan GeoJSON.',
                'is_secret' => false,
            ],

            // ── Routing ─────────────────────────────────────────────────────
            [
                'key' => 'graphhopper_enabled',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'routing',
                'label' => 'Aktifkan GraphHopper',
                'description' => 'Aktifkan mesin routing GraphHopper. Butuh API key; nyalakan hanya setelah key diisi. Tanpa key, routing tetap jalan lewat OSRM publik.',
                'is_secret' => false,
            ],
            [
                'key' => 'graphhopper_api_key',
                'value' => '',
                'type' => 'string',
                'group' => 'routing',
                'label' => 'GraphHopper API Key',
                'description' => 'API key GraphHopper. Kosongkan agar mesin ini otomatis dinonaktifkan.',
                'is_secret' => true,
            ],
            [
                'key' => 'graphhopper_url',
                'value' => 'https://graphhopper.com/api/1',
                'type' => 'string',
                'group' => 'routing',
                'label' => 'GraphHopper URL',
                'description' => 'Base URL GraphHopper API.',
                'is_secret' => false,
            ],
            [
                'key' => 'graphhopper_timeout',
                'value' => '30',
                'type' => 'integer',
                'group' => 'routing',
                'label' => 'GraphHopper Timeout (detik)',
                'description' => 'Batas waktu menunggu jawaban GraphHopper.',
                'is_secret' => false,
            ],
            [
                'key' => 'graphhopper_traffic_speed',
                'value' => '',
                'type' => 'string',
                'group' => 'routing',
                'label' => 'GraphHopper Traffic Speed',
                'description' => 'Kecepatan rata-rata (km/jam) yang diasumsikan mesin routing, mis. 60. Kosongkan untuk memakai bawaan.',
                'is_secret' => false,
            ],
            [
                'key' => 'osrm_local_enabled',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'routing',
                'label' => 'Aktifkan OSRM Lokal',
                'description' => 'Aktifkan OSRM di localhost. Nonaktifkan bila layanannya tidak berjalan.',
                'is_secret' => false,
            ],
            [
                'key' => 'osrm_local_url',
                'value' => 'http://127.0.0.1:5000',
                'type' => 'string',
                'group' => 'routing',
                'label' => 'OSRM Lokal URL',
                'description' => 'Base URL OSRM lokal.',
                'is_secret' => false,
            ],
            [
                'key' => 'osrm_local_timeout',
                'value' => '5',
                'type' => 'integer',
                'group' => 'routing',
                'label' => 'OSRM Lokal Timeout (detik)',
                'description' => 'Batas waktu OSRM lokal. Defaults sengaja pendek karena layanan ini biasanya paling dekat.',
                'is_secret' => false,
            ],
            [
                'key' => 'osrm_car_url',
                'value' => 'http://127.0.0.1:5000',
                'type' => 'string',
                'group' => 'routing',
                'label' => 'OSRM Mobil URL',
                'description' => 'Endpoint OSRM untuk profil mobil.',
                'is_secret' => false,
            ],
            [
                'key' => 'osrm_bike_url',
                'value' => 'http://127.0.0.1:5001',
                'type' => 'string',
                'group' => 'routing',
                'label' => 'OSRM Sepeda URL',
                'description' => 'Endpoint OSRM untuk profil sepeda.',
                'is_secret' => false,
            ],
            [
                'key' => 'osrm_walk_url',
                'value' => 'http://127.0.0.1:5002',
                'type' => 'string',
                'group' => 'routing',
                'label' => 'OSRM Jalan Kaki URL',
                'description' => 'Endpoint OSRM untuk profil jalan kaki.',
                'is_secret' => false,
            ],
            [
                'key' => 'osrm_public_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'routing',
                'label' => 'Aktifkan OSRM Publik',
                'description' => 'Aktifkan server OSRM publik sebagai fallback.',
                'is_secret' => false,
            ],
            [
                'key' => 'osrm_public_url',
                'value' => 'https://router.project-osrm.org',
                'type' => 'string',
                'group' => 'routing',
                'label' => 'OSRM Publik URL',
                'description' => 'Base URL OSRM publik. Dibatasi kuota, jangan untuk produksi besar-besaran.',
                'is_secret' => false,
            ],
            [
                'key' => 'osrm_public_timeout',
                'value' => '15',
                'type' => 'integer',
                'group' => 'routing',
                'label' => 'OSRM Publik Timeout (detik)',
                'description' => 'Batas waktu menunggu OSRM publik.',
                'is_secret' => false,
            ],

            // ── Traffic ─────────────────────────────────────────────────────
            [
                'key' => 'tomtom_api_key',
                'value' => '',
                'type' => 'string',
                'group' => 'traffic',
                'label' => 'TomTom API Key',
                'description' => 'API key TomTom Traffic. Kosongkan agar fitur data macet otomatis dinonaktifkan.',
                'is_secret' => true,
            ],
            [
                'key' => 'tomtom_traffic_url',
                'value' => 'https://api.tomtom.com/traffic/services/4',
                'type' => 'string',
                'group' => 'traffic',
                'label' => 'TomTom Traffic URL',
                'description' => 'Base URL TomTom Traffic API.',
                'is_secret' => false,
            ],
            [
                'key' => 'tomtom_timeout',
                'value' => '60',
                'type' => 'integer',
                'group' => 'traffic',
                'label' => 'TomTom Timeout (detik)',
                'description' => 'Batas waktu menunggu data macet dari TomTom.',
                'is_secret' => false,
            ],

            // ── Asisten AI ──────────────────────────────────────────────────
            [
                'key' => 'groq_api_key',
                'value' => '',
                'type' => 'string',
                'group' => 'ai',
                'label' => 'Groq API Key',
                'description' => 'API key Groq untuk asisten chatbot. Diuji otomatis ke Groq sebelum disimpan. Kosongkan untuk memakai GROQ_API_KEY dari .env.',
                'is_secret' => true,
            ],
            [
                'key' => 'groq_base_url',
                'value' => 'https://api.groq.com/openai/v1',
                'type' => 'string',
                'group' => 'ai',
                'label' => 'Base URL Groq',
                'description' => 'Endpoint API Groq (OpenAI-compatible).',
                'is_secret' => false,
            ],
            [
                'key' => 'groq_model',
                'value' => 'qwen/qwen3.8-27b',
                'type' => 'string',
                'group' => 'ai',
                'label' => 'Model AI',
                'description' => 'Model Groq untuk menjawab pertanyaan pengguna. Daftar model terbaru bisa dicek lewat tombol Tes.',
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
                // Biarkan apa adanya: nilai admin dan secret terenkripsi aman.
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

        // Hanya hapus baris yang masih persis sama dengan nilai default,
        // supaya perubahan admin tidak ikut hilang saat rollback.
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
