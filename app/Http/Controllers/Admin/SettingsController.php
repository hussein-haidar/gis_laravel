<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(Request $request): View
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

        return view('admin-settings.index', compact('settings', 'groups', 'group'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string', 'exists:settings,key'],
            'settings.*.value' => ['nullable'],
        ]);

        foreach ($validated['settings'] as $settingData) {
            $setting = Setting::where('key', $settingData['key'])->first();
            if ($setting) {
                $oldValue = $setting->value;
                $newValue = $settingData['value'];

                // Handle boolean checkboxes (unchecked = not sent)
                if ($setting->type === 'boolean' && !isset($settingData['value'])) {
                    $newValue = '0';
                }

                $setting->update(['value' => (string) $newValue]);

                ActivityLog::create([
                    'user_id' => Auth::id(),
                    'type' => 'setting_updated',
                    'subject_type' => Setting::class,
                    'subject_id' => $setting->id,
                    'old_values' => ['value' => $oldValue],
                    'new_values' => ['value' => $newValue],
                    'description' => 'Memperbarui pengaturan: ' . $setting->label,
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            }
        }

        return redirect()
            ->route('admin.settings.index', ['group' => $request->input('current_group', 'general')])
            ->with('success', 'Pengaturan berhasil diperbarui.');
    }
}