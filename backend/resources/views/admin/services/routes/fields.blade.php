{{-- Route code and optional cost fields. $plan, $codeValue, $costValue, $discountValue --}}
@php($control = 'block w-full rounded-lg border px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
<div>
    <label for="provider_plan_code" class="mb-1 block text-xs font-medium text-navy-700">Provider plan code</label>
    <input id="provider_plan_code" name="provider_plan_code" value="{{ $codeValue }}" autocomplete="off" placeholder="The provider's own code for this plan"
           @class([$control, 'border-red-400' => $errors->has('provider_plan_code'), 'border-navy-200' => ! $errors->has('provider_plan_code')])>
    @error('provider_plan_code')<p id="provider_plan_code-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    <p class="mt-1 text-xs text-navy-500">Required when the provider's service needs a plan code.</p>
</div>
@if ($plan->isVariable())
    <div>
        <label for="cost_discount" class="mb-1 block text-xs font-medium text-navy-700">Provider cost: discount off face value (%, optional)</label>
        <input id="cost_discount" name="cost_discount" value="{{ $discountValue }}" inputmode="decimal" autocomplete="off" placeholder="e.g. 3"
               @class([$control, 'border-red-400' => $errors->has('cost_discount'), 'border-navy-200' => ! $errors->has('cost_discount')])>
        @error('cost_discount')<p id="cost_discount-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
@else
    <div>
        <label for="cost" class="mb-1 block text-xs font-medium text-navy-700">Provider cost in ₦ (optional)</label>
        <input id="cost" name="cost" value="{{ $costValue }}" inputmode="decimal" autocomplete="off" placeholder="e.g. 250.00"
               @class([$control, 'border-red-400' => $errors->has('cost'), 'border-navy-200' => ! $errors->has('cost')])>
        @error('cost')<p id="cost-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
@endif
