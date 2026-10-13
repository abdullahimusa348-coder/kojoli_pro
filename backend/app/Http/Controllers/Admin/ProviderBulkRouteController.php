<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Providers\BulkAddRoutes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Providers\BulkRoutesRequest;
use App\Models\Product;
use App\Models\Provider;
use Illuminate\Http\RedirectResponse;

/** Bulk helper: one ordinary route per plan of a product (blank provider plan codes, no cost). */
class ProviderBulkRouteController extends Controller
{
    public function store(BulkRoutesRequest $request, Provider $provider, BulkAddRoutes $bulk): RedirectResponse
    {
        $product = Product::findOrFail($request->validated('product_id'));
        $result = $bulk->handle($provider, $product, (int) $request->validated('priority'), $request->user('admin'));

        $message = count($result['created'])." route(s) created for {$product->name}";
        if ($result['skipped'] !== []) {
            $message .= '; skipped: '.implode('; ', $result['skipped']);
        }

        return redirect()->route('admin.providers.show', $provider)->withFragment('routes')->with('status', $message.'.');
    }
}
