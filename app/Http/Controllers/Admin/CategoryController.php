<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim($request->query('search', ''));

        $categories = Category::query()
            ->withCount('locations')
            ->with('parent')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->ordered()
            ->paginate(10)
            ->withQueryString();

        return view('admin-cat.index', compact('categories', 'search'));
    }

    public function create(): View
    {
        $parents = Category::root()->ordered()->get();
        return view('admin-cat.create', compact('parents'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $category = Category::create($data);

        $this->logActivity('category_created', $category, null, $category->toArray());

        return redirect()
            ->route('admin.categories.index')
            ->with('success', 'Kategori berhasil ditambahkan.');
    }

    public function edit(Category $category): View
    {
        $parents = Category::root()->where('id', '!=', $category->id)->ordered()->get();
        return view('admin-cat.edit', compact('category', 'parents'));
    }

    public function update(Request $request, Category $category)
    {
        $oldValues = $category->toArray();

        $category->update($this->validated($request, $category->id));

        $this->logActivity('category_updated', $category, $oldValues, $category->fresh()->toArray());

        return redirect()
            ->route('admin.categories.index')
            ->with('success', 'Kategori berhasil diperbarui.');
    }

    public function destroy(Category $category)
    {
        $locationCount = $category->locations()->count();

        if ($locationCount > 0) {
            return back()
                ->with('error', "Kategori \"{$category->name}\" masih dipakai oleh {$locationCount} lokasi. Pindahkan atau hapus lokasinya terlebih dahulu.");
        }

        $oldValues = $category->toArray();

        $category->delete();

        $this->logActivity('category_deleted', null, $oldValues, null);

        return redirect()
            ->route('admin.categories.index')
            ->with('success', 'Kategori berhasil dihapus.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:categories,name' . ($ignoreId ? ",{$ignoreId}" : '')],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'icon' => ['nullable', 'string', 'max:50'],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'is_active' => ['boolean'],
        ]);
    }

    private function logActivity(string $type, ?Category $subject, ?array $oldValues, ?array $newValues): void
    {
        ActivityLog::create([
            'user_id' => Auth::id(),
            'type' => $type,
            'subject_type' => Category::class,
            'subject_id' => $subject?->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => match ($type) {
                'category_created' => 'Menambahkan kategori: ' . ($subject?->name ?? ''),
                'category_updated' => 'Memperbarui kategori: ' . ($subject?->name ?? $oldValues['name'] ?? ''),
                'category_deleted' => 'Menghapus kategori: ' . ($oldValues['name'] ?? ''),
                default => 'Aksi pada kategori',
            },
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}