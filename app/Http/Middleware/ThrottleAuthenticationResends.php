<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Middleware\ThrottleRequests;

class ThrottleAuthenticationResends extends ThrottleRequests
{
    protected function handleRequest($request, Closure $next, array $limits)
    {
        $response = parent::handleRequest($request, $next, $limits);
        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $data = $response->getData(true);
            if (isset($data['resend_after'])) {
                foreach ($limits as $limit) {
                    if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                        $data['resend_after'] = max($data['resend_after'], $this->limiter->availableIn($limit->key));
                    }
                }
                $response->setData($data);
            }
        }

        return $response;
    }
}
