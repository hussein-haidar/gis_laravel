<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Location;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * "Kelola Web" — pengaturan tampilan situs milik Super Admin.
 *
 * Dua kelompok yang diatur:
 * - Identitas situs: judul & deskripsi (dipakai sebagai title & meta).
 * - Banner & pengumuman: sakel aktif-tayang dan teksnya.
 *
 * Nilai disimpan lewat `Setting::setValue()` supaya konsisten dengan seluruh
 * konfigurasi lain di tabel `settings` (termasuk handling nilai kosong).
 */
class WebSettingController extends Controller
{
    /**
     * Definisi field. `type` harus cocok dengan kolom `type` di tabel
     * `settings` supaya `setValue()` menyimpan dalam format yang benar.
     *
     * @var array<string,array<string,mixed>>
     */
    private const FIELDS = [
        'site_title' => ['type' => 'string', 'label' => 'Judul Situs'],
        'site_description' => ['type' => 'text', 'label' => 'Deskripsi Situs'],
        'banner_enabled' => ['type' => 'boolean', 'label' => 'Tampilkan Banner'],
        'banner_title' => ['type' => 'string', 'label' => 'Judul Banner'],
        'banner_text' => ['type' => 'text', 'label' => 'Isi Banner'],
        'announcement_enabled' => ['type' => 'boolean', 'label' => 'Tampilkan Pengumuman'],
        'announcement_text' => ['type' => 'text', 'label' => 'Isi Pengumuman'],
    ];

    public function index()
    {
        $settings = [];

        foreach (array_keys(self::FIELDS) as $key) {
            $settings[$key] = Setting::getValue($key, '');
        }

        // Prefill dari data situs yang sedang aktif. Tanpa ini form tampil
        // kosong padahal situs sudah punya nama & deskripsi, dan Super Admin
        // berisiko menyimpan nilai kosong tanpa sadar.
        if (trim((string) $settings['site_title']) === '') {
            $settings['site_title'] = (string) config('app.name');
        }

        if (trim((string) $settings['site_description']) === '') {
            $settings['site_description'] = $this->deriveDescription();
        }

        return view('super-admin.web', [
            'settings' => $settings,
            'fields' => self::FIELDS,
        ]);
    }

    /**
     * Ringkasan singkat situs yang disusun dari data yang benar-benar ada:
     * jumlah lokasi & kategori, plus zona waktu aplikasi.
     */
    private function deriveDescription(): string
    {
        $locationCount = Location::publiclyVisible()->count();
        $categoryCount = Category::count();

        return sprintf(
            'Peta lokasi wisata dan tempat menarik — %d lokasi dari %d kategori (zona waktu %s).',
            $locationCount,
            $categoryCount,
            config('app.timezone')
        );
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'site_title' => ['required', 'string', 'max:120'],
            'site_description' => ['nullable', 'string', 'max:500'],
            'banner_title' => ['nullable', 'string', 'max:120'],
            'banner_text' => ['nullable', 'string', 'max:500'],
            'announcement_text' => ['nullable', 'string', 'max:500'],
            // Checkbox tidak terkirim saat tidak dicentang, jadi harus nullable.
            'banner_enabled' => ['nullable', 'boolean'],
            'announcement_enabled' => ['nullable', 'boolean'],
        ], [], [
            'site_title' => 'judul situs',
        ]);

        foreach (self::FIELDS as $key => $meta) {
            // Checkbox yang tidak dikirim berarti "dimatikan", bukan "diabaikan".
            $missing = ! array_key_exists($key, $validated);

            if ($missing && $meta['type'] === 'boolean') {
                $value = '0';
            } elseif ($missing) {
                continue;
            } else {
                $value = $validated[$key];
            }

            Setting::setValue($key, $value, $meta['type']);
        }

        return redirect()
            ->route('super-admin.web')
            ->with('success', 'Pengaturan situs tersimpan.');
    }
}
