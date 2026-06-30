<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscribed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->hasActiveSubscription()) {
            return response()->json([
                'message' => "Abonnement requis pour accéder à cette fonctionnalité.",
                'subscription_required' => true,
            ], 402); // 402 Payment Required
        }

        return $next($request);
    }
}
