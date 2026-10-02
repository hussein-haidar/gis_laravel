<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

class Setting extends Model
{
    /**
     * Penanda nilai secret yang sudah dienkripsi. Nilai ini Disimpan apa adanya
     * di kolom `value`, jadi aman kalau ada yang melakukan dump/query mentah.
     */
    public const ENCRYPTED_PREFIX = 'enc:v1:';

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'label',
        'description',
        'is_secret',
    ];

    protected $casts = [
        'is_secret' => 'boolean',
    ];

    /**
     * Secret otomatis terenkripsi setiap disimpan. Nilai yang sudah terenkripsi
     * tidak dienkripsi ulang, jadi aman dipanggil berulang.
     */
    protected static function booted(): void
    {
        static::saving(function (self $setting) {
            if (! $setting->is_secret) {
                return;
            }

            // Ambil nilai mentah yang baru diset, bukan hasil accessor (yang
            // sudah didekripsi). Kalau sudah terenkripsi, encryptValue
            // mengembalikannya apa adanya sehingga aman diulang.
            $raw = $setting->getAttributes()['value'] ?? null;

            $setting->setAttribute('value', static::encryptValue($raw));
        });
    }

    /**
     * Baca nilai: secret terenkripsi didekripsi otomatis, secret lama yang
     * masih plaintext dikembalikan apa adanya (kompatibilitas).
     */
    public function getValueAttribute($value)
    {
        if (! $this->is_secret || ! is_string($value) || ! static::looksEncrypted($value)) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, strlen(static::ENCRYPTED_PREFIX)));
        } catch (Throwable $e) {
            // APP_KEY berubah / ciphertext rusak. Jangan dibocorkan, tapi tetap
            // beri tahu supaya ketahuan, bukan diam-diam jadi kosong.
            Log::error('Gagal mendekripsi setting "'.$this->key.'". Pastikan APP_KEY tetap sama. '.$e->getMessage());

            return '';
        }
    }

    /**
     * Nilai apa adanya seperti tersimpan di database (tanpa dekripsi).
     * Dipakai command migrasi dan audit, bukan untuk dipakai aplikasi.
     */
    public function rawValue(): ?string
    {
        return $this->getRawOriginal('value');
    }

    public static function looksEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, static::ENCRYPTED_PREFIX);
    }

    /**
     * Enkripsi nilai secret. Idempoten dan tidak mengubah nilai kosong.
     */
    public static function encryptValue(?string $plain): ?string
    {
        if ($plain === null || $plain === '' || static::looksEncrypted($plain)) {
            return $plain;
        }

        return static::ENCRYPTED_PREFIX . Crypt::encryptString($plain);
    }

    /**
     * True kalau ada nilai tersimpan (terenkripsi atau plaintext lama).
     * Dipakai UI supaya nilai rahasia tidak pernah perlu dirender ke HTML.
     */
    public function hasStoredSecret(): bool
    {
        return trim((string) $this->value) !== '';
    }

    /**
     * Petakan setiap credential ke nilai .env sebagai cadangan. Sengaja memakai
     * jalur `config/*` (bukan helper env() langsung) supaya tetap bekerja saat
     * `php artisan config:cache` aktif.
     */
    public const ENV_FALLBACK = [
        'gis_api_key' => 'gis.api_key',
        'graphhopper_api_key' => 'routing.engines.graphhopper.api_key',
        'tomtom_api_key' => 'services.tomtom.key',
        'groq_api_key' => 'services.groq.key',
    ];

    /**
     * Baca credential dari database, lalu .env sebagai cadangan.
     *
     * Baris secret yang kosong di database dianggap "tidak diisi", bukan
     * " Sengaja dikosongkan", supaya admin bisa mengosongkan kolom lalu
     * otomatis kembali memakai .env. Secret tidak pernah dikembalikan dalam
     * bentuk apa pun untuk ditampilkan ke antarmuka.
     *
     * @return array{value: string, source: 'database'|'env'|'none'}
     */
    public static function credential(string $key): array
    {
        $stored = trim((string) static::getValue($key, ''));

        if ($stored !== '') {
            return ['value' => $stored, 'source' => 'database'];
        }

        $path = static::ENV_FALLBACK[$key] ?? null;
        $fromEnv = $path ? trim((string) config($path, '')) : '';

        if ($fromEnv !== '') {
            return ['value' => $fromEnv, 'source' => 'env'];
        }

        return ['value' => '', 'source' => 'none'];
    }

    public static function credentialValue(string $key): string
    {
        return static::credential($key)['value'];
    }

    public static function credentialSource(string $key): string
    {
        return static::credential($key)['source'];
    }

    /**
     * Ubah nilai mentah baris setting menjadi tipe yang dideklarasikan.
     * Dipisahkan supaya getValue() dan pembaca parameter mesin routing memakai
     * satu aturan casting yang sama.
     */
    public static function castValue(self $setting)
    {
        return match ($setting->type) {
            'boolean' => (bool) $setting->value,
            'integer' => (int) $setting->value,
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    public static function getValue(string $key, $default = null)
    {
        $setting = static::where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        return static::castValue($setting);
    }

    public static function setValue(string $key, $value, string $type = 'string'): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_array($value) || is_object($value) ? json_encode($value) : (string) $value, 'type' => $type]
        );
    }

    public function getDecryptedValueAttribute(): string
    {
        if ($this->is_secret) {
            return '••••••••';
        }

        return (string) $this->value;
    }
}
