<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RevenueController extends Controller
{
    /**
     * Statistiques de revenus filtrables par semaine / mois / année.
     * Agrégation côté PHP pour rester compatible avec PostgreSQL et SQLite.
     */
    public function index(Request $request)
    {
        $granularity = in_array($request->query('granularity'), ['week', 'month', 'year'], true)
            ? $request->query('granularity')
            : 'month';

        $year = (int) ($request->query('year') ?: Carbon::now()->year);

        $payments = Payment::where('status', 'completed')
            ->orderBy('created_at')
            ->get(['amount', 'created_at']);

        $now = Carbon::now();

        // KPIs globaux
        $kpis = [
            'total_all_time' => (int) $payments->sum('amount'),
            'payments_count' => $payments->count(),
            'this_week' => (int) $payments->filter(fn ($p) => $p->created_at && $p->created_at->greaterThanOrEqualTo($now->copy()->startOfWeek()))->sum('amount'),
            'this_month' => (int) $payments->filter(fn ($p) => $p->created_at && $p->created_at->greaterThanOrEqualTo($now->copy()->startOfMonth()))->sum('amount'),
            'this_year' => (int) $payments->filter(fn ($p) => $p->created_at && $p->created_at->year === $now->year)->sum('amount'),
        ];

        $series = match ($granularity) {
            'week' => $this->weeklySeries($payments, $now),
            'year' => $this->yearlySeries($payments),
            default => $this->monthlySeries($payments, $year),
        };

        // Liste des années disponibles (pour le sélecteur)
        $availableYears = $payments
            ->map(fn ($p) => $p->created_at?->year)
            ->filter()
            ->unique()
            ->sortDesc()
            ->values();

        if ($availableYears->isEmpty()) {
            $availableYears = collect([$now->year]);
        }

        return response()->json([
            'granularity' => $granularity,
            'year' => $year,
            'available_years' => $availableYears,
            'kpis' => $kpis,
            'series' => $series,
        ]);
    }

    private function monthlySeries($payments, int $year): array
    {
        $labels = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
        $buckets = array_fill(1, 12, ['revenue' => 0, 'count' => 0]);

        foreach ($payments as $p) {
            if ($p->created_at && $p->created_at->year === $year) {
                $m = (int) $p->created_at->month;
                $buckets[$m]['revenue'] += (int) $p->amount;
                $buckets[$m]['count']++;
            }
        }

        $series = [];
        for ($m = 1; $m <= 12; $m++) {
            $series[] = [
                'label' => $labels[$m - 1],
                'revenue' => $buckets[$m]['revenue'],
                'count' => $buckets[$m]['count'],
            ];
        }

        return $series;
    }

    private function weeklySeries($payments, Carbon $now): array
    {
        $series = [];
        for ($i = 11; $i >= 0; $i--) {
            $start = $now->copy()->startOfWeek()->subWeeks($i);
            $end = $start->copy()->endOfWeek();
            $revenue = 0;
            $count = 0;
            foreach ($payments as $p) {
                if ($p->created_at && $p->created_at->betweenIncluded($start, $end)) {
                    $revenue += (int) $p->amount;
                    $count++;
                }
            }
            $series[] = [
                'label' => $start->format('d/m'),
                'revenue' => $revenue,
                'count' => $count,
            ];
        }

        return $series;
    }

    private function yearlySeries($payments): array
    {
        $buckets = [];
        foreach ($payments as $p) {
            if (!$p->created_at) {
                continue;
            }
            $y = (int) $p->created_at->year;
            if (!isset($buckets[$y])) {
                $buckets[$y] = ['revenue' => 0, 'count' => 0];
            }
            $buckets[$y]['revenue'] += (int) $p->amount;
            $buckets[$y]['count']++;
        }

        ksort($buckets);

        $series = [];
        foreach ($buckets as $y => $data) {
            $series[] = [
                'label' => (string) $y,
                'revenue' => $data['revenue'],
                'count' => $data['count'],
            ];
        }

        if (empty($series)) {
            $series[] = ['label' => (string) Carbon::now()->year, 'revenue' => 0, 'count' => 0];
        }

        return $series;
    }
}
