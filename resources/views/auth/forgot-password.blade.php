<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lupa Password - {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center" style="min-height: 100vh;">

<div class="container" style="max-width: 420px;">
    <div class="text-center mb-4">
        <h3 class="mb-1">🗺️ {{ config('app.name') }}</h3>
        <p class="text-muted">Reset password Anda</p>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            @if ($errors->any())
                <div class="alert alert-danger">
                    @foreach ($errors->all() as $error)
                        {{ $error }}
                    @endforeach
                </div>
            @endif

            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            <p class="text-muted small">
                Masukkan email admin yang terdaftar. Kami akan mengirimkan link untuk mereset password.
            </p>

            <form action="{{ route('password.email') }}" method="POST">
                @csrf
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" name="email" id="email" class="form-control @error('email') is-invalid @enderror"
                           value="{{ old('email') }}" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary w-100">Kirim Link Reset</button>
            </form>
        </div>
    </div>

    <p class="text-center text-muted mt-4">
        <a href="{{ route('login') }}">← Kembali ke login</a>
    </p>
</div>

</body>
</html>
