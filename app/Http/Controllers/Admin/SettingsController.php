<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\Ai\GroqService;
use App\Services\Routing\RoutingService;
use App\Services\Traffic\TomTomTrafficService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /** Kunci yang wajib diuji ke provider sebelum boleh disimpan. */
    public const TESTABLE_KEYS = ['groq_api_key', 'tomtom_api_key', 'graphhopper_api_key'];

    public function index(Request $request, GroqService $groq, RoutingService $routing): View
    {
        $group = $request->query('group', 'general');

        // Semua group selalu dimuat. Tab di halaman ini dipindah klien lewat
        // Bootstrap, bukan lewat reload, jadi memfilter di query membuat tab
        // selain group aktif tampak kosong.
        $settings = Setting::query()
            ->orderBy('group')
            ->orderBy('label')
            ->get()
            ->groupBy('group');

        $groups = Setting::select('group')->distinct()->orderBy('group')->pluck('group')->toArray();

        // Group yang tidak punya isi tidak dijadikan tab.
        $groups = array_values(array_intersect($groups, array_keys($settings->all())));

        if ($group !== 'all' && ! in_array($group, $groups, true)) {
            $group = $groups[0] ?? 'all';
        }

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
    public function testKey(Request $request, GroqService $groq, RoutingService $routing, TomTomTrafficService $tomtom)
    {
        $validated = $request->validate([
            'key' => ['nullable', 'string', 'max:200'],
            'model' => ['nullable', 'string', 'max:120'],
        ]);

        $keyParam = $validated['key'] ?? null;
        $modelParam = $validated['model'] ?? null;

        // Tentukan key mana yang diuji berdasarkan input atau key yang dikirim
        $testKey = $keyParam ? $keyParam : '';

        // Jika kosong, kita tidak tahu, tapi controller dipanggil dengan key spesifik
        // Tapi untuk kompatibilitas: default ke groq
        $target = null;

        if ($keyParam) {
            $target = $keyParam;
        }

        // Coba deteksi dari referer? Tidak. Tapi tombol kirim key name
        // Gunakan field name dari request body? Kita kirim {key, model} tapi tidak kirim nama setting
        // Biar aman: jika keyParam mengandung pola tomtom/graphhopper -> uji sesuai
        $lower = strtolower((string) $keyParam);

        if ($lower && str_contains($lower, 'tomtom')) {
            $result = $tomtom->validateKey($keyParam);
            return response()->json($result, $result['valid'] ? 200 : 422);
        }

        if ($lower && str_contains($lower, 'graphhopper')) {
            $result = $routing->validateGraphhopperKey($keyParam);
            return response()->json($result, $result['valid'] ? 200 : 422);
        }

        // Default: groq
        $result = $groq->validateKey($keyParam, $modelParam);

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

            if (! $setting) {
                continue;
            }

            $value = $settingData['value'] ?? null;

            if ($setting->type === 'boolean' && ! isset($settingData['value'])) {
                $value = '0';
            }

            $bolehDihapus = ! empty($semuaInput[$key]['clear']);

            // Secret kosong berarti "jangan ubah", bukan "hapus".
            if ($setting->is_secret && trim((string) $value) === '' && ! $bolehDihapus) {
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

            if (! $hasil['valid']) {
                return redirect()
                    ->route('admin.settings.index', ['group' => $request->input('current_group', 'ai')])
                    ->withInput()
                    ->with('error', 'API key tidak disimpan karena tidak valid: '.$hasil['message']);
            }

            // Simpan pesan sukses dari tes validasi supaya terlihat di halaman.
            $request->session()->flash('ai_key_validated', $hasil['message']);
        }

        if (isset($nilaiBaru['tomtom_api_key']) && $nilaiBaru['tomtom_api_key']['value'] !== '') {
            $tomtom = app(\App\Services\Traffic\TomTomTrafficService::class);
            $hasil = $tomtom->validateKey($nilaiBaru['tomtom_api_key']['value']);

            if (! $hasil['valid']) {
                return redirect()
                    ->route('admin.settings.index', ['group' => $request->input('current_group', 'traffic')])
                    ->withInput()
                    ->with('error', 'API key TomTom tidak disimpan karena tidak valid: '.$hasil['message']);
            }
            $request->session()->flash('ai_key_validated', $hasil['message']);
        }

        if (isset($nilaiBaru['graphhopper_api_key']) && $nilaiBaru['graphhopper_api_key']['value'] !== '') {
            $routingService = app(\App\Services\Routing\RoutingService::class);
            $hasil = $routingService->validateGraphhopperKey($nilaiBaru['graphhopper_api_key']['value']);

            if (! $hasil['valid']) {
                return redirect()
                    ->route('admin.settings.index', ['group' => $request->input('current_group', 'routing')])
                    ->withInput()
                    ->with('error', 'API key GraphHopper tidak disimpan karena tidak valid: '.$hasil['message']);
            }
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
                'description' => 'Memperbarui pengaturan: '.$setting->label,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        }

        return redirect()
            ->route('admin.settings.index', ['group' => $request->input('current_group', 'general')])
            ->with('success', 'Pengaturan berhasil diperbarui.');
    }
}
