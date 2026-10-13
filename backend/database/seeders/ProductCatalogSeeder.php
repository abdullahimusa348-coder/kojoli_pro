<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Service;
use App\Support\Catalog\CatalogSlug;
use App\Support\Catalog\DefaultCatalog;
use Illuminate\Database\Seeder;

/**
 * Adds the minimal generic products (Data and Airtime per network, Smile Data
 * → Smile). Idempotent: matched by stable code, only missing products are
 * added, existing ones (and admin edits) are never changed. All seeded
 * disabled. No plans, prices or providers are seeded.
 */
class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (DefaultCatalog::products() as $serviceSlug => $products) {
            $service = Service::firstWhere('slug', $serviceSlug);

            if ($service === null) {
                continue;
            }

            foreach ($products as $order => $definition) {
                $code = CatalogSlug::productCode($serviceSlug, $definition['name']);

                if (Product::where('code', $code)->exists()) {
                    continue;
                }

                $product = new Product([
                    'service_id' => $service->id,
                    'name' => $definition['name'],
                    'network' => $definition['network'],
                    'sort_order' => ($order + 1) * 10,
                ]);
                $product->code = $code;
                $product->is_active = false;
                $product->save();
            }
        }
    }
}
