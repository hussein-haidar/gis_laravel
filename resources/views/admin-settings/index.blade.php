@extends('layouts.app')

@section('title', 'Pengaturan Sistem')

@php
    // Kunci yang mendapat tombol tes koneksi ke provider sebelum disimpan.
    $testableKeys = \App\Http\Controllers\Admin\SettingsController::TESTABLE_KEYS;
@endphp

@section('content')
    <h1 class="h3 mb-4">Pengaturan Sistem</h1>

    @if (session('ai_key_validated'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('ai_key_validated') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Status Mesin Routing</span>
            <code class="small text-body-secondary">php artisan routing:doctor</code>
        </div>
        <div class="card-body">
            @php
                $engineBadges = [
                    'ready' => ['SIAP', 'bg-success'],
                    'off' => ['MATI', 'bg-secondary'],
                    'needs_key' => ['BELUM ADA KEY', 'bg-warning text-dark'],
                    'unknown' => ['PERLU DIUJI', 'bg-info text-dark'],
                ];
            @endphp

            <div class="row g-3">
                @foreach ($engineStatus as $engineKey => $engine)
                    @php [$badgeText, $badgeClass] = $engineBadges[$engine['state']] ?? ['?', 'bg-secondary']; @endphp
                    <div class="col-12 col-lg-6">
                        <div class="border rounded p-3 h-100">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <span class="fw-semibold">{{ $engine['label'] }}</span>
                                <span class="badge {{ $badgeClass }}">{{ $badgeText }}</span>
                            </div>
                            <div class="small text-body-secondary">{{ $engine['note'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if (collect($engineStatus)->where('state', 'ready')->isEmpty())
                <div class="alert alert-warning mt-3 mb-0">
                    Tidak ada mesin routing yang siap. Navigasi dan rute tidak akan berfungsi sampai
                    OSRM Publik dinyalakan atau API key GraphHopper diisi.
                </div>
            @endif
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" id="settingsTabs" role="tablist">
                @foreach ($groups as $g)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link {{ $group === $g ? 'active' : '' }}" 
                                id="tab-{{ $g }}" data-bs-toggle="tab" data-bs-target="#panel-{{ $g }}" type="button" role="tab">
                            {{ ucfirst($g) }}
                        </button>
                    </li>
                @endforeach
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $group === 'all' ? 'active' : '' }}" 
                            id="tab-all" data-bs-toggle="tab" data-bs-target="#panel-all" type="button" role="tab">
                        Semua
                    </button>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <form action="{{ route('admin.settings.update') }}" method="POST">
                @csrf
                @method('PUT')
                <input type="hidden" name="current_group" value="{{ $group }}">

                <div class="tab-content" id="settingsTabContent">
                    @foreach ($settings as $g => $groupSettings)
                        <div class="tab-pane fade {{ $group === $g ? 'show active' : '' }}" id="panel-{{ $g }}" role="tabpanel">
                            @foreach ($groupSettings as $setting)
                                <div class="mb-3">
                                    <label for="setting-{{ $setting->key }}" class="form-label fw-semibold">
                                        {{ $setting->label }}
                                        @if ($setting->is_secret)
                                            <span class="badge bg-warning text-dark ms-1">Secret</span>
                                        @endif
                                    </label>
                                    @if ($setting->description)
                                        <div class="form-text">{{ $setting->description }}</div>
                                    @endif

                                    @if ($setting->type === 'boolean')
                                        <div class="form-check form-switch">
                                            <input type="hidden" name="settings[{{ $setting->key }}][key]" value="{{ $setting->key }}">
                                            <input type="checkbox" 
                                                   name="settings[{{ $setting->key }}][value]" 
                                                   id="setting-{{ $setting->key }}" 
                                                   class="form-check-input" 
                                                   value="1" 
                                                   {{ $setting->value ? 'checked' : '' }}>
                                            <label class="form-check-label" for="setting-{{ $setting->key }}">{{ $setting->value ? 'Aktif' : 'Tidak Aktif' }}</label>
                                        </div>
                                    @elseif ($setting->is_secret)
                                        @php $hasStored = $setting->hasStoredSecret(); @endphp
                                        <div class="input-group">
                                            {{-- Nilai secret tidak pernah dirender ke HTML. --}}
                                            <input type="password" 
                                                   name="settings[{{ $setting->key }}][value]" 
                                                   id="setting-{{ $setting->key }}" 
                                                   value="" 
                                                   autocomplete="new-password" 
                                                   class="form-control @error("settings.{$setting->key}.value") is-invalid @enderror" 
                                                   placeholder="{{ $hasStored ? 'Tersimpan (••••••••). Ketik nilai baru untuk mengganti.' : 'Masukkan nilai baru' }}">
                                            <input type="hidden" name="settings[{{ $setting->key }}][key]" value="{{ $setting->key }}">
                                            <button type="button" class="btn btn-outline-secondary toggle-password" data-target="#setting-{{ $setting->key }}" title="Lihat/sembunyikan">👁️</button>
                                            @if (in_array($setting->key, $testableKeys, true))
                                                <button type="button" 
                                                        class="btn btn-outline-primary test-key-btn"
                                                        data-target="#setting-{{ $setting->key }}"
                                                        data-model-target="#setting-groq_model">Tes</button>
                                            @endif
                                        </div>

                                        @if ($hasStored)
                                            <div class="form-check mt-2">
                                                <input class="form-check-input" type="checkbox"
                                                       name="settings[{{ $setting->key }}][clear]" value="1"
                                                       id="setting-{{ $setting->key }}-clear">
                                                <label class="form-check-label small text-danger" for="setting-{{ $setting->key }}-clear">
                                                    Hapus nilai tersimpan (fallback ke .env)
                                                </label>
                                            </div>
                                        @endif

                                        <div class="test-key-result mt-2" id="test-result-{{ $setting->key }}" style="display:none"></div>
                                        <div class="form-text">
                                            Biarkan kosong jika tidak ingin mengubah nilai tersimpan.
                                            Nilai disimpan terenkripsi di database.
                                        </div>
                                    @elseif ($setting->key === 'groq_model' && count($aiModels) > 0)
                                        <select name="settings[{{ $setting->key }}][value]" 
                                                id="setting-{{ $setting->key }}"
                                                class="form-select">
                                            @foreach ($aiModels as $m)
                                                <option value="{{ $m }}" {{ $setting->value === $m ? 'selected' : '' }}>{{ $m }}</option>
                                            @endforeach
                                        </select>
                                        <input type="hidden" name="settings[{{ $setting->key }}][key]" value="{{ $setting->key }}">
                                    @elseif ($setting->type === 'integer')
                                        <input type="number" 
                                               name="settings[{{ $setting->key }}][value]" 
                                               id="setting-{{ $setting->key }}" 
                                               value="{{ $setting->value }}" 
                                               class="form-control @error("settings.{$setting->key}.value") is-invalid @enderror">
                                        <input type="hidden" name="settings[{{ $setting->key }}][key]" value="{{ $setting->key }}">
                                    @else
                                        <input type="text" 
                                               name="settings[{{ $setting->key }}][value]" 
                                               id="setting-{{ $setting->key }}" 
                                               value="{{ $setting->value }}" 
                                               class="form-control @error("settings.{$setting->key}.value") is-invalid @enderror">
                                        <input type="hidden" name="settings[{{ $setting->key }}][key]" value="{{ $setting->key }}">
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                    <a href="{{ route('admin.locations.index') }}" class="btn btn-outline-secondary">Kembali</a>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('.toggle-password').forEach(btn => {
        btn.addEventListener('click', function() {
            const target = document.querySelector(this.dataset.target);
            if (target.type === 'password') {
                target.type = 'text';
                this.textContent = '🙈';
            } else {
                target.type = 'password';
                this.textContent = '👁️';
            }
        });
    });

    (function () {
        const TEST_URL = @json(route('admin.settings.test-key'));
        const CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        function showResult(key, message, ok) {
            const box = document.getElementById('test-result-' + key);
            if (!box) return;
            box.style.display = 'block';
            box.className = 'test-key-result mt-2 alert py-2 px-3 mb-0 small ' + (ok ? 'alert-success' : 'alert-danger');
            box.textContent = message;
        }

        document.querySelectorAll('.test-key-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const input = document.querySelector(btn.dataset.target);
                const modelInput = btn.dataset.modelTarget
                    ? document.querySelector(btn.dataset.modelTarget)
                    : null;

                const key = input ? input.value.trim() : '';
                const model = modelInput ? modelInput.value : null;
                const settingKey = input.id.replace('setting-', '');

                const original = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = key ? 'Menguji...' : 'Menguji key tersimpan...';

                fetch(TEST_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': CSRF,
                    },
                    body: JSON.stringify({ key: key, model: model }),
                })
                    .then(function (res) {
                        return res.json()
                            .catch(function () {
                                return { message: 'Server merespons HTTP ' + res.status + '.' };
                            })
                            .then(function (data) {
                                showResult(settingKey, data.message || 'Tidak ada pesan dari server.', res.ok);
                            });
                    })
                    .catch(function () {
                        showResult(settingKey, 'Gagal menghubungi server.', false);
                    })
                    .finally(function () {
                        btn.disabled = false;
                        btn.innerHTML = original;
                    });
            });
        });
    })();
</script>
@endpush