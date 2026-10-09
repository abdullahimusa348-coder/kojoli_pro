<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Catalog\SaveService;
use App\Actions\Admin\Catalog\SetCatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalog\ServiceRequest;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\Catalog\CatalogIcon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Catalog services (Services area, Services tab). Catalog entries only: no
 * plans, pricing, providers or purchasing. Uses the services.* permissions.
 */
class ServiceController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', Rule::exists('service_categories', 'id')],
            'status' => ['nullable', 'in:active,disabled,available'],
        ]);

        $services = Service::query()
            ->with('category')
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('slug', 'like', $like)->orWhere('description', 'like', $like));
            })
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category_id', $category))
            ->when(($filters['status'] ?? null) === 'available', fn ($query) => $query->available())
            ->when(in_array($filters['status'] ?? null, ['active', 'disabled'], true), fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            // Group by category (in category display order), then each service's own order.
            ->orderBy(ServiceCategory::select('sort_order')->whereColumn('service_categories.id', 'services.category_id'))
            ->orderBy('category_id')
            ->ordered()
            ->paginate(15)
            ->withQueryString();

        return view('admin.services.index', [
            'services' => $services,
            'filters' => $filters,
            'categories' => ServiceCategory::ordered()->get(['id', 'name']),
        ]);
    }

    public function create(): View
    {
        return view('admin.services.create', $this->formData());
    }

    public function store(ServiceRequest $request, SaveService $save): RedirectResponse
    {
        $service = $save->create($request->validated(), $request->user('admin'));

        return redirect()->route('admin.services.show', $service)->with('status', "Service “{$service->name}” created.");
    }

    public function show(Service $service): View
    {
        return view('admin.services.show', [
            'service' => $service->load('category'),
            'products' => $service->products()->ordered()->get(),
        ]);
    }

    public function edit(Service $service): View
    {
        return view('admin.services.edit', ['service' => $service] + $this->formData());
    }

    public function update(ServiceRequest $request, Service $service, SaveService $save): RedirectResponse
    {
        $save->update($service, $request->validated(), $request->user('admin'));

        return redirect()->route('admin.services.show', $service)->with('status', 'Service updated.');
    }

    public function updateStatus(Request $request, Service $service, SetCatalogStatus $set): RedirectResponse
    {
        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $set->handle($service, (bool) $active, $request->user('admin'));

        return back()->with('status', $active ? "Service “{$service->name}” enabled." : "Service “{$service->name}” disabled.");
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'categories' => ServiceCategory::ordered()->get(['id', 'name', 'is_active']),
            'icons' => CatalogIcon::cases(),
        ];
    }
}
