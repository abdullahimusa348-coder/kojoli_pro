<?php

namespace App\Http\Requests\Admin\Providers;

use App\Models\Plan;
use App\Models\PlanProviderRoute;
use App\Models\ProviderService;
use App\Support\Money;
use App\Support\Pricing\BasisPoints;
use App\Support\Pricing\KoboAmount;
use App\Support\Pricing\PricingLimits;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add or edit a plan provider route. On add: a provider with an active
 * capability for the plan's service, not already routed, at an unused
 * priority. The provider plan code is required when the capability requires
 * one. Cost is optional: naira for fixed plans, a discount percentage for
 * variable plans.
 */
class PlanRouteRequest extends FormRequest
{
    private function plan(): Plan
    {
        return $this->route('plan');
    }

    private function editing(): ?PlanProviderRoute
    {
        $route = $this->route('route');

        return $route instanceof PlanProviderRoute ? $route : null;
    }

    private function capability(): ?ProviderService
    {
        $providerId = $this->editing()?->provider_id ?? $this->input('provider_id');

        return ProviderService::where('provider_id', $providerId)->where('service_id', $this->plan()->product->service_id)->first();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $plan = $this->plan();
        $rules = [
            'provider_plan_code' => ['nullable', 'string', 'max:100', 'regex:/^[\x21-\x7E]+(?: [\x21-\x7E]+)*$/',
                Rule::requiredIf(fn () => (bool) $this->capability()?->requires_plan_code)],
            'cost' => ['nullable', $plan->isVariable() ? 'prohibited' : null, function (string $attribute, mixed $value, Closure $fail) {
                $kobo = KoboAmount::parse($value);
                $max = PricingLimits::maxAmountKobo();
                if ($kobo === null) {
                    $fail('Enter an amount in naira, e.g. 1,250.50.');
                } elseif ($kobo < 1) {
                    $fail('The cost must be at least ₦0.01.');
                } elseif ($kobo > $max) {
                    $fail('The cost may not be more than '.Money::format($max).' (system maximum).');
                }
            }],
            'cost_discount' => ['nullable', $plan->isVariable() ? null : 'prohibited', function (string $attribute, mixed $value, Closure $fail) {
                if (BasisPoints::parse($value) === null) {
                    $fail('Enter a discount from 0 to 99.99 (percent, up to 2 decimal places).');
                }
            }],
        ];
        $rules['cost'] = array_values(array_filter($rules['cost']));
        $rules['cost_discount'] = array_values(array_filter($rules['cost_discount']));

        if ($this->editing() === null) {
            $rules['provider_id'] = ['required', 'integer', Rule::exists('providers', 'id'),
                Rule::unique('plan_provider_routes', 'provider_id')->where('plan_id', $plan->id),
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! $this->capability()?->is_active) {
                        $fail('This provider has no active service for this plan.');
                    }
                }];
            $rules['priority'] = ['required', 'integer', 'min:1', 'max:999',
                Rule::unique('plan_provider_routes', 'priority')->where('plan_id', $plan->id)];
        }

        return $rules;
    }

    /** @return array{provider_plan_code: ?string, cost_kobo: ?int, cost_discount_bps: ?int} */
    public function routeData(): array
    {
        return [
            'provider_plan_code' => $this->input('provider_plan_code'),
            'cost_kobo' => $this->plan()->isVariable() ? null : KoboAmount::parse($this->input('cost')),
            'cost_discount_bps' => $this->plan()->isVariable() ? BasisPoints::parse($this->input('cost_discount')) : null,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'provider_id.unique' => 'This provider already has a route for this plan.',
            'priority.unique' => 'This priority is already used by another route for this plan.',
            'provider_plan_code.required' => 'This provider requires its own plan code for this service.',
            'provider_plan_code.regex' => 'Use visible characters only (single spaces allowed between them).',
            'cost.prohibited' => 'Variable-amount plans use a cost discount, not a fixed cost.',
            'cost_discount.prohibited' => 'Fixed plans use a fixed cost, not a discount.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['provider_id' => 'provider', 'provider_plan_code' => 'provider plan code'];
    }
}
