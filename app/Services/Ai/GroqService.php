<?php

namespace App\Services\Ai;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqService
{
    /**
     * API key aktif. Prioritas: nilai di database (diinput admin lewat
     * halaman Pengaturan) lalu fallback ke GROQ_API_KEY di .env.
     */
    public function key(): ?string
    {
        return $this->keySource()['value'];
    }

    /**
     * Key aktif beserta asalnya, untuk pesan diagnostik di halaman admin.
     *
     * @return array{value:?string,source:string}
     */
    public function keySource(): array
    {
        // Resolusi credential cukup di satu tempat: Setting::credential().
        $credential = Setting::credential('groq_api_key');

        if ($credential['source'] === 'none') {
            return ['value' => null, 'source' => 'kosong'];
        }

        return ['value' => $credential['value'], 'source' => $credential['source']];
    }

    public function baseUrl(): string
    {
        $fromDb = trim((string) Setting::getValue('groq_base_url', ''));

        return rtrim($fromDb !== '' ? $fromDb : (string) config('services.groq.base_url'), '/');
    }

    public function model(): string
    {
        $fromDb = trim((string) Setting::getValue('groq_model', ''));

        return $fromDb !== '' ? $fromDb : (string) config('services.groq.model');
    }

    public function enabled(): bool
    {
        return !empty($this->key());
    }

    /**
     * Periksa apakah API key benar-benar valid sebelum disimpan.
     * Menguji dua hal: endpoint /models (kredensial) dan chat completion
     * singkat (model benar-benar bisa dipakai).
     *
     * @param  string|null  $key  null = pakai key tersimpan (DB / .env)
     * @return array{valid:bool,message:string,source?:string,models?:array<int,string>}
     */
    public function validateKey(?string $key = null, ?string $model = null): array
    {
        $key = trim((string) $key);
        $source = 'input';

        if ($key === '') {
            $stored = $this->keySource();
            $key = (string) ($stored['value'] ?? '');
            $source = $stored['source'] === 'database' ? 'database' : ($stored['source'] === 'env' ? 'env' : 'kosong');
        }

        $model = $model !== null && trim($model) !== '' ? trim($model) : $this->model();

        if ($key === '') {
            return ['valid' => false, 'message' => 'API key kosong. Isi key atau set GROQ_API_KEY di .env.'];
        }

        if (!str_starts_with($key, 'gsk_')) {
            return ['valid' => false, 'message' => 'Format key Groq harus diawali "gsk_".'];
        }

        $baseUrl = $this->baseUrl();

        try {
            $modelsResp = Http::withToken($key)
                ->acceptJson()
                ->timeout(20)
                ->get($baseUrl . '/models');
        } catch (\Throwable $e) {
            return ['valid' => false, 'message' => 'Tidak bisa menghubungi Groq: ' . $e->getMessage()];
        }

        if ($modelsResp->status() === 401) {
            return ['valid' => false, 'message' => 'API key ditolak Groq (401 Unauthorized).'];
        }

        if (!$modelsResp->ok()) {
            return [
                'valid' => false,
                'message' => 'Groq merespons HTTP ' . $modelsResp->status() . ' saat memverifikasi key.',
            ];
        }

        $models = collect($modelsResp->json('data') ?? [])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        // Uji chat singkat supaya model yang dipilih ikut terkonfirmasi.
        try {
            $chatResp = Http::withToken($key)
                ->acceptJson()
                ->timeout(30)
                ->post($baseUrl . '/chat/completions', [
                    'model' => $model,
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                    'max_tokens' => 5,
                ]);
        } catch (\Throwable $e) {
            return ['valid' => false, 'message' => 'Key diterima tapi gagal tes chat: ' . $e->getMessage()];
        }

        if ($chatResp->status() === 401) {
            return ['valid' => false, 'message' => 'API key ditolak Groq (401 Unauthorized).'];
        }

        if (!$chatResp->ok()) {
            $error = $chatResp->json('error.message') ?? ('HTTP ' . $chatResp->status());

            return [
                'valid' => false,
                'message' => "Key valid, tapi model \"{$model}\" gagal dipakai: " . $error,
                'source' => $source,
                'models' => $models,
            ];
        }

        $label = match ($source) {
            'database' => 'API key tersimpan di database valid.',
            'env' => 'API key dari .env valid.',
            default => 'API key valid.',
        };

        return [
            'valid' => true,
            'message' => $label . " Model \"{$model}\" siap dipakai.",
            'source' => $source,
            'models' => $models,
        ];
    }

    /**
     * Kirim percakapan ke Groq (OpenAI-compatible) dan ambil teks balasan.
     *
     * @param  array<int,array{role:string,content:string}>  $messages
     * @param  string|null  $model  null = model default
     */
    public function chat(array $messages, ?string $model = null): ?string
    {
        if (!$this->enabled()) {
            return null;
        }

        try {
            $response = Http::withToken((string) $this->key())
                ->acceptJson()
                ->timeout((int) config('services.groq.timeout', 45))
                ->post($this->baseUrl() . '/chat/completions', [
                    'model' => $model ?: $this->model(),
                    'messages' => $messages,
                    'temperature' => 0.4,
                    'max_tokens' => (int) config('services.groq.max_tokens', 900),
                ]);
        } catch (\Throwable $e) {
            Log::error('Groq request gagal: ' . $e->getMessage());
            return null;
        }

        if ($response->status() === 429) {
            Log::warning('Groq rate limit tercapai');
            return null;
        }

        if ($response->status() === 401) {
            Log::error('Groq menolak API key (401). Perbarui key di halaman Pengaturan.');
            return null;
        }

        if (!$response->ok()) {
            Log::error('Groq HTTP ' . $response->status() . ': ' . $response->body());
            return null;
        }

        $message = $response->json('choices.0.message');

        if (!is_array($message)) {
            return null;
        }

        // Model reasoning (mis. openai/gpt-oss-*) menaruh hasil di
        // reasoning_content dan membiarkan content kosong, jadi ikut dibaca.
        foreach (['content', 'reasoning_content', 'reasoning'] as $field) {
            $value = $message[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Daftar model yang bisa dipakai dengan key aktif, untukdropdown.
     */
    public function availableModels(): array
    {
        if (!$this->enabled()) {
            return [];
        }

        try {
            $resp = Http::withToken((string) $this->key())
                ->acceptJson()
                ->timeout(20)
                ->get($this->baseUrl() . '/models');
        } catch (\Throwable $e) {
            Log::error('Groq daftar model gagal: ' . $e->getMessage());
            return [];
        }

        if (!$resp->ok()) {
            return [];
        }

        return collect($resp->json('data') ?? [])
            ->pluck('id')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }
}
