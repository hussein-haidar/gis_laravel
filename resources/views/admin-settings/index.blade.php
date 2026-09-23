@extends('layouts.app')

@section('title', 'Pengaturan Sistem')

@section('content')
    <h1 class="h3 mb-4">Pengaturan Sistem</h1>

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
                                        <div class="input-group">
                                            <input type="password" 
                                                   name="settings[{{ $setting->key }}][value]" 
                                                   id="setting-{{ $setting->key }}" 
                                                   value="{{ $setting->value }}" 
                                                   class="form-control @error("settings.{$setting->key}.value") is-invalid @enderror" 
                                                   placeholder="Masukkan nilai baru (kosongkan untuk tidak mengubah)">
                                            <input type="hidden" name="settings[{{ $setting->key }}][key]" value="{{ $setting->key }}">
                                            <button type="button" class="btn btn-outline-secondary toggle-password" data-target="#setting-{{ $setting->key }}">👁️</button>
                                        </div>
                                        <div class="form-text">Kosongkan jika tidak ingin mengubah nilai.</div>
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
</script>
@endpush