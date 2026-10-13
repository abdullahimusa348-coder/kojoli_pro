<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\AuthenticateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueTokenRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Sanctum personal access tokens for the mobile app and API clients.
 * Same credential checks as the web login (AuthenticateUser).
 */
class TokenController extends Controller
{
    public function store(IssueTokenRequest $request, AuthenticateUser $authenticate): JsonResponse
    {
        $user = $authenticate->handle($request->validated('login'), $request->validated('password'), (string) $request->ip());

        // Lifetime comes from config('sanctum.expiration') (SANCTUM_TOKEN_EXPIRATION, minutes); null = no expiry.
        $minutes = config('sanctum.expiration');
        $expiresAt = $minutes ? now()->addMinutes((int) $minutes) : null;

        $token = $user->createToken($request->validated('device_name'), ['*'], $expiresAt);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $expiresAt?->toIso8601String(),
            'user' => new UserResource($user),
        ], Response::HTTP_CREATED);
    }

    /** Revoke the token used for this request. */
    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
