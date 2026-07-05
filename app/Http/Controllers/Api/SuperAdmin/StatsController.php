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
        Carbon::setLocale('fr');

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

            // Nouvelles données pour le dashboard enrichi
            'kpis' => $this->kpis($now),
            'performance' => $this->performanceSeries($now),
            'categories' => $this->categories(),
            'recent_activity' => $this->recentActivity(),
        ]);
    }

    private function kpis(Carbon $now): array
    {
        $startThisMonth = $now->copy()->startOfMonth();
        $startLastMonth = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $endLastMonth = $startThisMonth->copy()->subSecond();

        // QCM créés
        $quizThis = Quiz::where('created_at', '>=', $startThisMonth)->count();
        $quizLast = Quiz::whereBetween('created_at', [$startLastMonth, $endLastMonth])->count();

        // Soumissions (tests passés)
        $subThis = Submission::where('created_at', '>=', $startThisMonth)->count();
        $subLast = Submission::whereBetween('created_at', [$startLastMonth, $endLastMonth])->count();

        // Score moyen (%)
        $avgAll = (float) Submission::whereNotNull('percentage')->avg('percentage');
        $avgThis = (float) Submission::where('created_at', '>=', $startThisMonth)->whereNotNull('percentage')->avg('percentage');
        $avgLast = (float) Submission::whereBetween('created_at', [$startLastMonth, $endLastMonth])->whereNotNull('percentage')->avg('percentage');

        return [
            'total_assessments' => (int) Quiz::count(),
            'total_assessments_change' => $this->pct($quizThis, $quizLast),
            'completed' => (int) Submission::count(),
            'completed_change' => $this->pct($subThis, $subLast),
            'avg_score' => round($avgAll, 0),
            'avg_score_change' => $this->pct($avgThis, $avgLast),
        ];
    }

    private function pct($current, $previous): float
    {
        $current = (float) $current;
        $previous = (float) $previous;
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function performanceSeries(Carbon $now): array
    {
        $labels = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
        $subs = Submission::where('created_at', '>=', $now->copy()->subDays(6)->startOfDay())
            ->get(['created_at']);

        $series = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $now->copy()->subDays($i);
            $count = $subs->filter(fn ($s) => $s->created_at && $s->created_at->isSameDay($day))->count();
            $series[] = [
                'label' => $labels[$day->dayOfWeek],
                'date' => $day->format('d/m'),
                'value' => $count,
            ];
        }

        return $series;
    }

    private function categories(): array
    {
        $counts = Quiz::selectRaw('school_class_id, COUNT(*) as total')
            ->groupBy('school_class_id')
            ->pluck('total', 'school_class_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $classNames = SchoolClass::whereIn('id', $counts->keys()->filter()->all())
            ->pluck('name', 'id');

        $items = [];
        foreach ($counts as $classId => $total) {
            $items[] = [
                'label' => $classId ? ($classNames[$classId] ?? 'Classe') : 'Sans classe',
                'value' => (int) $total,
            ];
        }

        usort($items, fn ($a, $b) => $b['value'] <=> $a['value']);

        // Limite à 5 catégories + "Autres"
        if (count($items) > 5) {
            $top = array_slice($items, 0, 5);
            $othersTotal = array_sum(array_map(fn ($i) => $i['value'], array_slice($items, 5)));
            if ($othersTotal > 0) {
                $top[] = ['label' => 'Autres', 'value' => $othersTotal];
            }
            return $top;
        }

        return $items;
    }

    private function recentActivity(): array
    {
        $subs = Submission::with(['quiz:id,title', 'user:id,name'])
            ->latest()
            ->take(6)
            ->get();

        return $subs->map(function ($s) {
            $prenom = trim((string) $s->participant_prenom);
            $nom = trim((string) $s->participant_nom);
            $participant = trim($prenom . ' ' . $nom);
            if ($participant === '') {
                $participant = $s->user->name ?? 'Participant';
            }

            return [
                'title' => $s->quiz->title ?? 'QCM supprimé',
                'participant' => $participant,
                'note' => $s->note_sur_20 !== null ? round((float) $s->note_sur_20, 1) : null,
                'time_ago' => $s->created_at ? $s->created_at->diffForHumans() : '',
            ];
        })->all();
    }
}
