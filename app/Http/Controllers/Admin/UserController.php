<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $isSuper = $request->user()->isSuperAdmin();
        $roleName = $request->query('role');
        $search = trim($request->query('search', ''));

        $users = User::query()
            ->with('role')
            ->when(!$isSuper, fn ($q) => $q->whereHas('role', fn ($r) => $r->where('name', 'user')))
            ->when($request->user()->isAdmin() && $roleName === 'all', fn ($q) => $q) // admin hanya melihat role user
            ->when($isSuper && in_array($roleName, ['user', 'admin', 'super_admin']), fn ($q) => $q->whereHas('role', fn ($r) => $r->where('name', $roleName)))
            ->when($search !== '', fn ($q) => $q->where(function ($qq) use ($search) {
                $qq->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
            }))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin-users.index', compact('users', 'search', 'roleName'));
    }

    public function create(Request $request): View
    {
        $roles = $this->allowedRoles($request);

        return view('admin-users.create', compact('roles'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        abort_unless(in_array($data['role_id'], $this->allowedRoles($request)->pluck('id')->all(), true), 403);

        $data['password'] = Hash::make($data['password']);
        User::create($data);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User berhasil ditambahkan.');
    }

    public function edit(Request $request, User $user): View
    {
        $this->ensureAccessible($request, $user);
        $roles = $this->allowedRoles($request);

        return view('admin-users.edit', compact('user', 'roles'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->ensureAccessible($request, $user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email,' . $user->id],
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        abort_unless(in_array($data['role_id'], $this->allowedRoles($request)->pluck('id')->all(), true), 403);

        if ($request->filled('password')) {
            $request->validate(['password' => ['required', 'string', 'min:8', 'confirmed']]);
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User berhasil diperbarui.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Tidak bisa menghapus akun sendiri.');
        }

        $this->ensureAccessible($request, $user);

        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User berhasil dihapus.');
    }

    private function allowedRoles(Request $request): \Illuminate\Support\Collection
    {
        return Role::orderBy('name')
            ->when(!$request->user()->isSuperAdmin(), fn ($q) => $q->where('name', 'user'))
            ->get();
    }

    private function ensureAccessible(Request $request, User $user): void
    {
        if ($request->user()->isSuperAdmin()) {
            return;
        }

        abort_unless($user->role?->name === 'user', 403, 'Anda hanya dapat mengelola user dengan role User.');
    }
}