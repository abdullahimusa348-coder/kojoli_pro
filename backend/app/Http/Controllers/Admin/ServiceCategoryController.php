<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Catalog\SaveCategory;
use App\Actions\Admin\Catalog\SetCatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalog\CategoryRequest;
use App\Models\ServiceCategory;
use App\Support\Catalog\CatalogIcon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Catalog categories (Services area, Categories tab). Uses the services.* permissions. */
class ServiceCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,disabled'],
        ]);

        $categories = ServiceCategory::query()
            ->withCount('services')
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('slug', 'like', $like)->orWhere('description', 'like', $like));
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('is_active', $status === 'active'))
            ->ordered()
            ->paginate(15)
            ->withQueryString();

        return view('admin.services.categories.index', ['categories' => $categories, 'filters' => $filters]);
    }

    public function create(): View
    {
        return view('admin.services.categories.create', ['icons' => CatalogIcon::cases()]);
    }

    public function store(CategoryRequest $request, SaveCategory $save): RedirectResponse
    {
        $category = $save->create($request->validated(), $request->user('admin'));

        return redirect()->route('admin.services.categories.show', $category)->with('status', "Category “{$category->name}” created.");
    }

    public function show(ServiceCategory $category): View
    {
        return view('admin.services.categories.show', [
            'category' => $category,
            'services' => $category->services()->ordered()->get(),
        ]);
    }

    public function edit(ServiceCategory $category): View
    {
        return view('admin.services.categories.edit', ['category' => $category, 'icons' => CatalogIcon::cases()]);
    }

    public function update(CategoryRequest $request, ServiceCategory $category, SaveCategory $save): RedirectResponse
    {
        $save->update($category, $request->validated(), $request->user('admin'));

        return redirect()->route('admin.services.categories.show', $category)->with('status', 'Category updated.');
    }

    public function updateStatus(Request $request, ServiceCategory $category, SetCatalogStatus $set): RedirectResponse
    {
        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $set->handle($category, (bool) $active, $request->user('admin'));

        return back()->with('status', $active
            ? "Category “{$category->name}” enabled."
            : "Category “{$category->name}” disabled. Its services are unavailable until it is enabled again.");
    }
}
