@extends('layouts.app')

@section('title', 'Ganti Password')

@section('content')
    <h1 class="h3 mb-4">Ganti Password</h1>

    <div class="card" style="max-width: 520px;">
        <div class="card-body">
            <form action="{{ route('admin.password.update') }}" method="POST">
                @csrf

                <div class="mb-3">
                    <label for="current_password" class="form-label">Password Saat Ini <span class="text-danger">*</span></label>
                    <input type="password" name="current_password" id="current_password"
                           class="form-control @error('current_password') is-invalid @enderror" required autocomplete="current-password">
                    @error('current_password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password Baru <span class="text-danger">*</span></label>
                    <input type="password" name="password" id="password"
                           class="form-control @error('password') is-invalid @enderror" required autocomplete="new-password">
                    <div class="form-text">Minimal 8 karakter.</div>
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="password_confirmation" class="form-label">Ulangi Password Baru <span class="text-danger">*</span></label>
                    <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" required>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Simpan Password</button>
                    <a href="{{ route('admin.locations.index') }}" class="btn btn-secondary">Batal</a>
                </div>
            </form>
        </div>
    </div>
@endsection
