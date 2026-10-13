<?php

namespace App\Http\Middleware;

use App\Support\Phone\NigerianPhone;
use App\Support\Purchases\RecipientType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ordinary staff purchase search is a GET form, so its term travels in the
 * page address (Phase 11 CP3). A term that matches the 11-digit NIN/BVN rule
 * (RecipientType) and is not an accepted phone number (NigerianPhone: a local
 * phone number is also 11 digits, and phone search is unchanged) is never
 * searched, echoed, logged or kept. The request is answered with a redirect to
 * the plain purchase list and a neutral message pointing to the POST NIN/BVN
 * search (PurchaseIdentitySearchController).
 * - Its own address is rewritten without the term first, so the session's
 *   previous URL (stored for every GET request) never holds it.
 * - It runs before authentication (priority list, bootstrap/app.php), so a
 *   signed-out visitor's intended URL never holds it either.
 */
class RefuseIdentityNumberSearch
{
    public const MESSAGE = 'NIN and BVN numbers are not searched here. Use the NIN or BVN search below.';

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::looksLikeIdentityNumber($request->query('q'))) {
            return $next($request);
        }

        $request->query->replace([]);
        $request->server->set('QUERY_STRING', '');

        return redirect()->route('admin.purchases')->withErrors(['q' => self::MESSAGE]);
    }

    /** A NIN/BVN-shaped term (or a list holding one) that is not an accepted phone number. */
    public static function looksLikeIdentityNumber(mixed $term): bool
    {
        if (is_array($term)) {
            return collect($term)->flatten()->contains(fn ($item) => self::looksLikeIdentityNumber($item));
        }

        return is_string($term) && NigerianPhone::normalize($term) === null
            && collect([RecipientType::Nin, RecipientType::Bvn])->contains(fn (RecipientType $type) => $type->normalize($term) !== null);
    }
}
