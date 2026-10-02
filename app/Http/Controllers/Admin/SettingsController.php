<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\Ai\GroqService;
use App\Services\Routing\RoutingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /** Kunci yang wajib diuji ke provider sebelum boleh disimpan. */
    public const TESTABLE_KEYS = ['groq_api_key'];

    public function index(Request $request, GroqService $groq, RoutingService $routing): View
    {
        $group = $request->query('group', 'general');

        $settings = Setting::query()
            ->when($group !== 'all', function ($query) use ($group) {
                $query->where('group', $group);
            })
            ->orderBy('group')
            ->orderBy('label')
            ->get()
            ->groupBy('group');

        $groups = Setting::select('group')->distinct()->pluck('group')->toArray();

        $aiModels = $groq->enabled() ? $groq->availableModels() : [];

        // Panel status: mesin mana yang benar-benar akan dipakai. Tanpa probe
        // jaringan supaya halaman settings tetap cepat.
        $engineStatus = $routing->status();

        return view('admin-settings.index', compact('settings', 'groups', 'group', 'aiModels', 'engineStatus'));
    }

    /**
     * Uji API key tanpa menyimpan. Dipakai tombol "Tes" di halaman Pengaturan
     * supaya admin bisa memastikan key benar sebelum menekan Simpan.
     */
    public function testKey(Request $request, GroqService $groq)
    {
        $validated = $request->validate([
            'key' => ['nullable', 'string', 'max:200'],
            'model' => ['nullable', 'string', 'max:120'],
        ]);

        // Key kosong = tes key yang sedang tersimpan (DB, lalu .env).
        $result = $groq->validateKey($validated['key'] ?? null, $validated['model'] ?? null);

        return response()->json($result, $result['valid'] ? 200 : 422);
    }

    public function update(Request $request, GroqService $groq)
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string', 'exists:settings,key'],
            'settings.*.value' => ['nullable'],
        ]);

        // validate() hanya mengembalikan field yang divalidasi, jadi checkbox
        // "hapus nilai" dibaca langsung dari input mentah.
        $semuaInput = $request->input('settings', []);

        // Kumpulkan nilai baru per kunci supaya bisa diuji sebelum ditulis.
        $nilaiBaru = [];
        foreach ($validated['settings'] as $settingData) {
            $key = $settingData['key'];
            $setting = Setting::where('key', $key)->first();

            if (!$setting) {
                continue;
            }

            $value = $settingData['value'] ?? null;

            if ($setting->type === 'boolean' && !isset($settingData['value'])) {
                $value = '0';
            }

            $bolehDihapus = !empty($semuaInput[$key]['clear']);

            // Secret kosong berarti "jangan ubah", bukan "hapus".
            if ($setting->is_secret && trim((string) $value) === '' && !$bolehDihapus) {
                continue;
            }

            if ($setting->is_secret && $bolehDihapus) {
                $value = '';
            }

            $nilaiBaru[$key] = ['setting' => $setting, 'value' => (string) $value];
        }

        // API key wajib lolos tes provider dulu sebelum disimpan.
        if (isset($nilaiBaru['groq_api_key']) && $nilaiBaru['groq_api_key']['value'] !== '') {
            $model = $nilaiBaru['groq_model']['value'] ?? $groq->model();

            $hasil = $groq->validateKey($nilaiBaru['groq_api_key']['value'], $model);

            if (!$hasil['valid']) {
                return redirect()
                    ->route('admin.settings.index', ['group' => $request->input('current_group', 'ai')])
                    ->withInput()
                    ->with('error', 'API key tidak disimpan karena tidak valid: ' . $hasil['message']);
            }

            // Simpan pesan sukses dari tes validasi supaya terlihat di halaman.
            $request->session()->flash('ai_key_validated', $hasil['message']);
        }

        foreach ($nilaiBaru as $key => $data) {
            $setting = $data['setting'];
            // Jangan pernah memegang plaintext secret, bahkan sebentar.
            $oldHadValue = $setting->is_secret ? $setting->hasStoredSecret() : ($setting->value !== '');

            $setting->update(['value' => $data['value']]);

            // Jangan catat nilai secret mentah ke activity log.
            ActivityLog::create([
                'user_id' => Auth::id(),
                'type' => 'setting_updated',
                'subject_type' => Setting::class,
                'subject_id' => $setting->id,
                'old_values' => $setting->is_secret
                    ? ['value' => $oldHadValue ? '••••••••' : '']
                    : ['value' => $setting->getRawOriginal('value')],
                'new_values' => $setting->is_secret
                    ? ['value' => $data['value'] !== '' ? '••••••••' : '']
                    : ['value' => $data['value']],
                'description' => 'Memperbarui pengaturan: ' . $setting->label,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        }

        return redirect()
            ->route('admin.settings.index', ['group' => $request->input('current_group', 'general')])
            ->with('success', 'Pengaturan berhasil diperbarui.');
    }
}
