<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if (!$user || !$user->hasFeature($feature)) {
            return response()->json([
                'message' => "Votre formule ne donne pas accès à cette fonctionnalité.",
                'upgrade_required' => true,
                'feature_required' => $feature,
                'current_plan' => $user?->effectiveSubscriptionPlan(),
            ], 403);
        }

        return $next($request);
    }
}
