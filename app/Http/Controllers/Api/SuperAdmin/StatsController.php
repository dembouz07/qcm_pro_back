<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Quiz;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Carbon;

class StatsController extends Controller
{
    public function index()
    {
        $now = Carbon::now();

        $usersByRole = User::selectRaw('role, COUNT(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        $activeSubscriptions = User::where('subscription_status', 'active')
            ->where(function ($q) use ($now) {
                $q->whereNull('subscribed_until')
                  ->orWhere('subscribed_until', '>', $now);
            })
            ->where('role', 'admin')
            ->count();

        $revenue = (int) Payment::where('status', 'completed')->sum('amount');

        return response()->json([
            'users' => [
                'total' => (int) User::count(),
                'admins' => (int) ($usersByRole['admin'] ?? 0),
                'students' => (int) ($usersByRole['student'] ?? 0),
                'superadmins' => (int) ($usersByRole['superadmin'] ?? 0),
                'blocked' => (int) User::where('is_blocked', true)->count(),
            ],
            'quizzes' => [
                'total' => (int) Quiz::count(),
                'published' => (int) Quiz::where('is_published', true)->count(),
                'progressive' => (int) Quiz::where('type', 'progressive')->count(),
            ],
            'submissions' => [
                'total' => (int) Submission::count(),
                'last_7_days' => (int) Submission::where('created_at', '>=', $now->copy()->subDays(7))->count(),
            ],
            'classes' => [
                'total' => (int) SchoolClass::count(),
            ],
            'subscriptions' => [
                'active' => $activeSubscriptions,
            ],
            'revenue' => [
                'total_fcfa' => $revenue,
                'payments_completed' => (int) Payment::where('status', 'completed')->count(),
            ],
        ]);
    }
}
