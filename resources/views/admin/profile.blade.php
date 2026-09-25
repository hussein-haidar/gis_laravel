@extends('layouts.app')

@section('title', 'Profil Saya')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h4 class="mb-0"><i class="bi bi-person-circle me-2"></i> Profil Saya</h4>
            </div>
            <div class="card-body">
                @if (session('success'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        {{ session('success') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                <form action="{{ $user->hasRole('super_admin') ? route('super-admin.profile.update') : route('admin.profile.update') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="row mb-4">
                        <div class="col-md-3 text-center">
                            @if ($user->avatar)
                                <img src="{{ asset('storage/' . $user->avatar) }}" alt="Avatar" class="rounded-circle border" width="120" height="120" style="object-fit: cover;">
                            @else
                                <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center text-primary" style="width: 120px; height: 120px;">
                                    <i class="bi bi-person-fill" style="font-size: 3rem;"></i>
                                </div>
                            @endif
                            <div class="mt-3">
                                <label for="avatar" class="btn btn-outline-primary btn-sm w-100">
                                    <i class="bi bi-camera me-1"></i> Ganti Foto
                                    <input type="file" name="avatar" id="avatar" accept="image/*" class="d-none" onchange="previewAvatar(this)">
                                </label>
                            </div>
                        </div>
                        <div class="col-md-9">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Nama Lengkap</label>
                                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required>
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Email</label>
                                <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $user->email) }}" required>
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Role</label>
                                <input type="text" class="form-control bg-light" value="{{ $user->role->name ?? '-' }}" readonly>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <h5 class="mb-3">Ganti Password (Opsional)</h5>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Password Saat Ini</label>
                            <input type="password" name="current_password" class="form-control @error('current_password') is-invalid @enderror" autocomplete="current-password">
                            @error('current_password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Password Baru</label>
                            <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password" minlength="8">
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Konfirmasi Password</label>
                            <input type="password" name="password_confirmation" class="form-control @error('password_confirmation') is-invalid @enderror" autocomplete="new-password" minlength="8">
                            @error('password_confirmation')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Simpan Perubahan</button>
                        <a href="{{ auth()->user()->hasRole('super_admin') ? route('super-admin.dashboard') : route('admin.dashboard') }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = document.querySelector('.rounded-circle.border, .bg-primary.bg-opacity-10');
            if (img.tagName === 'IMG') {
                img.src = e.target.result;
            } else {
                img.outerHTML = `<img src="${e.target.result}" alt="Avatar Preview" class="rounded-circle border" width="120" height="120" style="object-fit: cover;">`;
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
@endsection