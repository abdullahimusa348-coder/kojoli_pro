<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Catalog\SaveProduct;
use App\Actions\Admin\Catalog\SetCatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalog\ProductRequest;
use App\Models\Product;
use App\Models\Service;
use App\Support\Catalog\Network;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Catalog products (Services area, Products tab). Structure only; uses the services.* permissions. */
class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'service' => ['nullable', 'integer', Rule::exists('services', 'id')],
            'network' => ['nullable', Rule::enum(Network::class)],
            'status' => ['nullable', 'in:active,disabled,available'],
        ]);

        $products = Product::query()
            ->with('service.category')
            ->withCount('plans')
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('description', 'like', $like));
            })
            ->when($filters['service'] ?? null, fn ($query, $service) => $query->where('service_id', $service))
            ->when($filters['network'] ?? null, fn ($query, $network) => $query->where('network', $network))
            ->when(($filters['status'] ?? null) === 'available', fn ($query) => $query->available())
            ->when(in_array($filters['status'] ?? null, ['active', 'disabled'], true), fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('service_id')
            ->ordered()
            ->paginate(15)
            ->withQueryString();

        return view('admin.services.products.index', [
            'products' => $products,
            'filters' => $filters,
            'services' => Service::ordered()->get(['id', 'name']),
            'networks' => Network::cases(),
        ]);
    }

    public function create(): View
    {
        return view('admin.services.products.create', $this->formData());
    }

    public function store(ProductRequest $request, SaveProduct $save): RedirectResponse
    {
        $product = $save->create($request->validated(), $request->user('admin'));

        return redirect()->route('admin.services.products.show', $product)->with('status', "Product “{$product->name}” created.");
    }

    public function show(Product $product): View
    {
        return view('admin.services.products.show', [
            'product' => $product->load('service.category'),
            'plans' => $product->plans()->ordered()->get(),
        ]);
    }

    public function edit(Product $product): View
    {
        return view('admin.services.products.edit', ['product' => $product] + $this->formData());
    }

    public function update(ProductRequest $request, Product $product, SaveProduct $save): RedirectResponse
    {
        $save->update($product, $request->validated(), $request->user('admin'));

        return redirect()->route('admin.services.products.show', $product)->with('status', 'Product updated.');
    }

    public function updateStatus(Request $request, Product $product, SetCatalogStatus $set): RedirectResponse
    {
        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $set->handle($product, (bool) $active, $request->user('admin'));

        return back()->with('status', $active
            ? "Product “{$product->name}” enabled."
            : "Product “{$product->name}” disabled. Its plans are unavailable until it is enabled again.");
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'services' => Service::with('category')->ordered()->get(),
            'networks' => Network::cases(),
        ];
    }
}
