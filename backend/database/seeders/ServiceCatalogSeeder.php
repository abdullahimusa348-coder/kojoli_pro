<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\Catalog\CatalogSlug;
use App\Support\Catalog\DefaultCatalog;
use Illuminate\Database\Seeder;

/**
 * Adds the starting service catalog. Idempotent: categories and services are
 * matched by slug, only missing ones are added, and existing rows (including
 * any admin edits) are never changed. Categories are added active; services
 * are added disabled. No plans, prices or providers are created.
 */
class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (DefaultCatalog::categories() as $categoryOrder => $categoryDef) {
            $category = ServiceCategory::firstWhere('slug', CatalogSlug::from($categoryDef['name']));

            if ($category === null) {
                $category = new ServiceCategory([
                    'name' => $categoryDef['name'],
                    'description' => $categoryDef['description'],
                    'icon' => $categoryDef['icon'],
                    'sort_order' => ($categoryOrder + 1) * 10,
                ]);
                $category->slug = CatalogSlug::from($categoryDef['name']);
                $category->is_active = true;
                $category->save();
            }

            foreach ($categoryDef['services'] as $serviceOrder => $serviceDef) {
                $slug = CatalogSlug::from($serviceDef['name']);

                if (Service::where('slug', $slug)->exists()) {
                    continue;
                }

                $service = new Service([
                    'category_id' => $category->id,
                    'name' => $serviceDef['name'],
                    'description' => $serviceDef['description'],
                    'icon' => $serviceDef['icon'],
                    'sort_order' => ($serviceOrder + 1) * 10,
                ]);
                $service->slug = $slug;
                $service->is_active = false;
                $service->save();
            }
        }
    }
}
