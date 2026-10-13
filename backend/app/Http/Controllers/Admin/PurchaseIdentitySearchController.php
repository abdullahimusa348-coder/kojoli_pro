<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Purchases\IdentityHasher;
use App\Support\Purchases\RecipientType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff exact-match search for NIN/BVN purchases (Phase 11 CP3, the existing
 * purchases.view permission). POST only, so the number is never in a URL; it
 * is never echoed back, flashed (dontFlash), logged or stored. The staff
 * session keeps only its keyed lookup hashes (current and previous app keys,
 * IdentityHasher) for a short time, and the purchase list filters on them
 * (?identity=1), still showing only masked numbers and never result values.
 * The hashes are type-prefixed, so a NIN search never matches a BVN.
 */
class PurchaseIdentitySearchController extends Controller
{
    private const SESSION_KEY = 'admin_purchase_identity_search';

    private const LIFETIME_MINUTES = 15;

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('identity', [
            'identity_type' => ['required', Rule::in(['nin', 'bvn'])],
            'identity_number' => ['required', 'string', 'max:30'],
        ], [
            'identity_type.required' => 'Choose NIN or BVN.',
            'identity_type.in' => 'Choose NIN or BVN.',
            'identity_number.required' => 'Enter the number to find.',
            'identity_number.string' => 'Enter a valid 11-digit number.',
            'identity_number.max' => 'Enter a valid 11-digit number.',
        ]);
        $type = RecipientType::from($data['identity_type']);
        $number = $type->normalize($data['identity_number']);
        if ($number === null) {
            return redirect()->route('admin.purchases')->withInput(['identity_type' => $type->value])
                ->withErrors(['identity_number' => $type->invalidMessage()], 'identity');
        }

        $request->session()->put(self::SESSION_KEY, [
            'type' => $type->value,
            'hashes' => IdentityHasher::lookupHashes($type, $number),
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES)->getTimestamp(),
        ]);

        return redirect()->route('admin.purchases', ['identity' => 1]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('admin.purchases');
    }

    /**
     * The staff member's current search (type and keyed lookup hashes), or
     * null when there is none or it has expired (then it is removed).
     *
     * @return array{type: RecipientType, hashes: list<string>}|null
     */
    public static function current(Request $request): ?array
    {
        $search = $request->session()->get(self::SESSION_KEY);
        $type = is_array($search) ? RecipientType::tryFrom((string) ($search['type'] ?? '')) : null;
        $hashes = is_array($search['hashes'] ?? null) ? array_values(array_filter($search['hashes'], 'is_string')) : [];
        if ($type === null || ! $type->isIdentity() || $hashes === [] || ! is_int($search['expires_at'] ?? null) || $search['expires_at'] < now()->getTimestamp()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return ['type' => $type, 'hashes' => $hashes];
    }
}
