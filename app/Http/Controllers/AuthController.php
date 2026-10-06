<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            $user = Auth::user();
            if ($user->hasRole('super_admin')) {
                return redirect()->intended(route('super-admin.dashboard'));
            }

            return redirect()->intended(route('admin.locations.index'));
        }

        return back()
            ->withErrors(['email' => 'Email atau password salah.'])
            ->onlyInput('email');
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $userRoleName = $request->role ?? 'user';
        $userRole = Role::where('name', $userRoleName)->firstOrFail();

        // For admin/superadmin registration, create pending account for approval
        if ($userRoleName === 'admin' || $userRoleName === 'super_admin') {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role_id' => $userRole->id,
                'is_pending' => true,
            ]);

            // Notify superadmin/admin for approval
            $notifiable = $userRoleName === 'admin' ? auth()->user() : auth()->user();
            if ($notifiable) {
                $notifiable->notify(new \App\Notifications\NewAdminRegistration($user, auth()->user()));
            }

            return back()->with('info', 'Permintaan pendaftaran admin baru telah dikirim ke superadmin/admin untuk diverifikasi.');
        }

        // Regular user registration
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role_id' => $userRole->id,
        ]);

        Auth::login($user);

        return redirect()->route('map.index')->with('success', 'Registrasi berhasil! Selamat datang.');
    }

    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect()->route('login')->withErrors(['email' => 'Gagal login dengan Google. Silakan coba lagi.']);
        }

        $user = User::where('google_id', $googleUser->getId())->first();

        if (! $user) {
            $user = User::where('email', $googleUser->getEmail())->first();

            // Download avatar Google & simpan lokal
            $avatarPath = $this->downloadAndStoreGoogleAvatar($googleUser->getAvatar());

            if ($user) {
                $user->update([
                    'google_id' => $googleUser->getId(),
                    // Foto profil milik user sendiri tidak boleh ditimpa foto
                    // Google: Google hanya jadi sumber awal. avatar_url null
                    // berarti kolom kosong ATAU berkas uploadnya sudah hilang,
                    // dan dua-duanya layak diisi ulang dari Google.
                    'avatar' => $user->avatar_url ?: $avatarPath,
                ]);
            } else {
                $userRole = Role::where('name', 'user')->first();
                $user = User::create([
                    'name' => $googleUser->getName() ?? $googleUser->getEmail(),
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                    'avatar' => $avatarPath,
                    'password' => Hash::make(\Str::random(24)),
                    'role_id' => $userRole->id,
                ]);
            }
        }

        Auth::login($user, true);

        return redirect()->intended(route('map.index'));
    }

    /**
     * Download avatar dari Google & simpan ke storage/app/public/avatars/
     * Supaya foto tidak bergantung pada URL temporary Google.
     */
    protected function downloadAndStoreGoogleAvatar(?string $avatarUrl): ?string
    {
        if (empty($avatarUrl)) {
            return null;
        }

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 10]);
            $response = $client->get($avatarUrl);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $content = $response->getBody()->getContents();
            $mimeType = $response->getHeaderLine('Content-Type');
            $extension = match (true) {
                str_starts_with($mimeType, 'image/jpeg') => 'jpg',
                str_starts_with($mimeType, 'image/png') => 'png',
                str_starts_with($mimeType, 'image/webp') => 'webp',
                default => 'jpg',
            };

            $filename = 'google_' . Str::random(16) . '.' . $extension;
            $path = 'avatars/' . $filename;

            Storage::disk('public')->put($path, $content);

            return $path;
        } catch (\Exception $e) {
            // Gagal download → return null, fallback ke default
            return null;
        }
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('map.index');
    }

    public function showForgotForm(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT || $status === Password::INVALID_USER) {
            return back()->with('status', 'Jika email Anda terdaftar, link reset password telah dikirim. Periksa juga folder spam.');
        }

        return back()
            ->withErrors(['email' => __($status)])
            ->onlyInput('email');
    }

    public function showResetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->email,
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->update(['password' => Hash::make($password)]);
                event(new PasswordReset($user));
                Auth::login($user);
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('success', 'Password berhasil direset.')
            : back()->withErrors(['email' => 'Token reset tidak valid atau kadaluarsa.']);
    }

    public function showPasswordForm(): View
    {
        return view('admin.password');
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            return back()->withErrors([
                'current_password' => 'Password saat ini salah.',
            ]);
        }

        $request->user()->update([
            'password' => Hash::make($data['password']),
        ]);

        return redirect()
            ->route('password.change')
            ->with('success', 'Password berhasil diganti.');
    }

    public function showProfile(): View
    {
        return view('admin.profile', ['user' => auth()->user()]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            // Bagian "Ganti Password (Opsional)" di form profil.
            'current_password' => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $data['avatar'] = $path;
        }

        // Password hanya diubah kalau kedua field baru benar-benar diisi.
        $passwordBaru = trim((string) ($data['password'] ?? ''));
        $passwordLama = trim((string) ($data['current_password'] ?? ''));

        if ($passwordBaru !== '') {
            if ($passwordLama === '' || ! Hash::check($passwordLama, $user->password)) {
                return back()
                    ->withInput($request->except(['current_password', 'password', 'password_confirmation', 'avatar']))
                    ->withErrors(['current_password' => 'Password saat ini salah.']);
            }

            $user->password = Hash::make($passwordBaru);
        }

        unset($data['password'], $data['current_password'], $data['password_confirmation']);

        $user->fill($data)->save();

        return back()->with('success', 'Profil berhasil diperbarui.');
    }
}
