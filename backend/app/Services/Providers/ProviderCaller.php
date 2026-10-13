<?php

namespace App\Services\Providers;

use App\Exceptions\Providers\ProviderCallUncertain;
use App\Exceptions\Providers\ProviderRequestRefused;
use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\ProviderContext;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderQueryRequest;
use App\Services\Providers\Data\ProviderResult;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Calls an adapter and guarantees a normalized ProviderResult. Anything the
 * adapter did not map to a documented outcome becomes unknown: timeouts,
 * connection failures, local refusals, unexpected exceptions. It never turns
 * an error into failed_definite. Purchase calls are made exactly once.
 */
class ProviderCaller
{
    public function purchase(ProviderAdapter $adapter, ProviderPurchaseRequest $request, ProviderContext $context): ProviderResult
    {
        return $this->guarded($adapter, 'purchase', fn () => $adapter->purchase($request, $context));
    }

    public function query(ProviderAdapter $adapter, ProviderQueryRequest $request, ProviderContext $context): ProviderResult
    {
        if (! $adapter->canQuery()) {
            throw new LogicException('This provider adapter has no documented status query.');
        }

        return $this->guarded($adapter, 'query', fn () => $adapter->query($request, $context));
    }

    /** @param  callable(): ProviderResult  $call */
    private function guarded(ProviderAdapter $adapter, string $kind, callable $call): ProviderResult
    {
        try {
            $result = $call();
        } catch (ProviderCallUncertain) {
            return ProviderResult::unknown('no_response', 'The provider did not answer in time.');
        } catch (ProviderRequestRefused) {
            return ProviderResult::unknown('refused_locally', 'The provider request was refused by our safety checks.');
        } catch (Throwable $e) {
            Log::error('Provider adapter error', ['driver' => $adapter->driver(), 'call' => $kind, 'exception' => $e::class]);

            return ProviderResult::unknown('adapter_error', 'The provider response could not be processed.');
        }

        return $result instanceof ProviderResult ? $result : ProviderResult::unknown('adapter_error', 'The provider response could not be processed.');
    }
}
