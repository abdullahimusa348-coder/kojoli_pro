<?php

namespace App\Actions\Admin\Providers;

use App\Models\Product;
use App\Models\Provider;
use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use Illuminate\Validation\ValidationException;

/**
 * Bulk helper: adds the provider at the given priority to every plan of a
 * product, creating one ordinary route row per plan with a blank provider
 * plan code and no cost. Never creates inherited or product-level routes.
 * Plans that already have this provider, or already use that priority, are
 * skipped and reported (nothing is reordered).
 */
class BulkAddRoutes
{
    public function __construct(private ProviderRules $rules, private SavePlanRoute $routes) {}

    /** @return array{created: list<string>, skipped: list<string>} plan names */
    public function handle(Provider $provider, Product $product, int $priority, SystemUser $actor): array
    {
        $this->rules->authorize($actor, SystemPermission::ProvidersUpdate, SystemPermission::ServicesView);

        $supported = $provider->services()->where('service_id', $product->service_id)->where('is_active', true)->exists();
        if (! $supported) {
            throw ValidationException::withMessages(['product_id' => 'This provider has no active service for this product.']);
        }

        $result = ['created' => [], 'skipped' => []];
        foreach ($product->plans()->ordered()->get() as $plan) {
            try {
                $this->routes->create($plan, $provider, ['priority' => $priority], $actor, 'created');
                $result['created'][] = $plan->name;
            } catch (ValidationException $e) {
                $result['skipped'][] = $plan->name.' ('.collect($e->errors())->flatten()->first().')';
            }
        }

        return $result;
    }
}
