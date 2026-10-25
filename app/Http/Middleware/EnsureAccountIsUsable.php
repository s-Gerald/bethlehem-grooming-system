<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsUsable
{
    /**
     * Re-check mutable account state for every authenticated API request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $attributes = $user?->getAttributes() ?? [];
        $isInactive = array_key_exists('is_active', $attributes)
            && ! (bool) $attributes['is_active'];
        $isArchived = array_key_exists('is_archived', $attributes)
            && (bool) $attributes['is_archived'];

        if ($user?->requiresPasswordSetup()) {
            $this->revokeCurrentToken($user);

            return new JsonResponse([
                'success' => false,
                'code' => 'password_setup_required',
                'message' => 'This staff account requires password setup.',
            ], 403);
        }

        if ($isInactive || $isArchived || $user?->hasEmailRecoveryHold()) {
            $this->revokeCurrentToken($user);

            return new JsonResponse([
                'success' => false,
                'code' => 'account_disabled',
                'message' => 'This account is disabled. Please contact the clinic.',
            ], 403);
        }

        if ($user->role === 'customer'
            && array_key_exists('email_verified_at', $attributes)
            && ! $user->email_verified_at) {
            $this->revokeCurrentToken($user);

            return new JsonResponse([
                'success' => false,
                'code' => 'email_not_verified',
                'email_not_verified' => true,
                'email' => $user->email,
                'message' => 'Please verify your email address before signing in.',
            ], 403);
        }

        return $next($request);
    }

    private function revokeCurrentToken($user): void
    {
        $accessToken = $user?->currentAccessToken();

        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }
    }
}
