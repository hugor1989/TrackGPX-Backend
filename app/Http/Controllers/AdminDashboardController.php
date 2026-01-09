<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Payment;
use App\Models\Plan;
use Faker\Provider\Base;

class AdminDashboardController extends AppBaseController
{
    public function index(Request $request)
    {
        // =============================
        // Fechas base
        // =============================
        $startMonth = Carbon::now()->startOfMonth();
        $endMonth   = Carbon::now()->endOfMonth();

        // =============================
        // 1️⃣ KPIs PRINCIPALES
        // =============================
        $subscriptionsThisMonth = Subscription::whereBetween('created_at', [$startMonth, $endMonth])->count();

        $newCustomers = Customer::whereBetween('created_at', [$startMonth, $endMonth])->count();

        $cancellations = Subscription::where('status', Subscription::STATUS_CANCELLED)
            ->whereBetween('updated_at', [$startMonth, $endMonth])
            ->count();

        $revenueThisMonth = Payment::where('status', 'approved')
            ->whereBetween('paid_at', [$startMonth, $endMonth])
            ->sum('amount');

        $netGrowth = $newCustomers - $cancellations;

        // =============================
        // 2️⃣ DISTRIBUCIÓN POR PLANES
        // =============================
        $plansDistribution = Plan::withCount([
            'subscriptions as subscriptions_count' => function ($query) use ($startMonth, $endMonth) {
                $query->whereBetween('created_at', [$startMonth, $endMonth]);
            }
        ])->get()->map(function ($plan) {
            return [
                'plan' => $plan->name,
                'price' => (float) $plan->price,
                'subscriptions' => $plan->subscriptions_count,
                // 👇 NECESARIO PARA EL PIE CHART
                'color' => $plan->color ?? '#3a7d44',
            ];
        });

        // =============================
        // 3️⃣ INGRESOS VS SUSCRIPCIONES (MENSUAL)
        // =============================
        $monthlyRevenue = Payment::selectRaw('
            DATE_FORMAT(paid_at, "%Y-%m") as month,
            SUM(amount) as revenue,
            COUNT(DISTINCT subscription_id) as subscriptions
        ')
            ->where('status', 'approved')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn($row) => [
                'month' => $row->month, // ej: 2026-01
                'revenue' => (float) $row->revenue,
                'subscriptions' => (int) $row->subscriptions,
            ]);

        // =============================
        // 4️⃣ CRECIMIENTO NETO
        // =============================
        $growth = Subscription::selectRaw('
            DATE_FORMAT(created_at, "%Y-%m") as month,
            COUNT(*) as new_subscriptions,
            SUM(status = "cancelled") as cancelled
        ')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn($row) => [
                'month' => $row->month,
                'new' => (int) $row->new_subscriptions,
                'cancelled' => (int) $row->cancelled,
                'net' => (int) $row->new_subscriptions - (int) $row->cancelled,
            ]);

        // =============================
        // 5️⃣ INGRESOS ANUALES + CRECIMIENTO %
        // =============================
        $yearlyRevenueRaw = Payment::selectRaw('
            YEAR(paid_at) as year,
            SUM(amount) as revenue
        ')
            ->where('status', 'approved')
            ->groupBy('year')
            ->orderBy('year')
            ->get()
            ->values();

        $yearlyRevenue = $yearlyRevenueRaw->map(function ($item, $index) use ($yearlyRevenueRaw) {
            $prev = $yearlyRevenueRaw[$index - 1] ?? null;

            $growth = $prev && $prev->revenue > 0
                ? round((($item->revenue - $prev->revenue) / $prev->revenue) * 100, 1)
                : 0;

            return [
                'year' => (string) $item->year,
                'revenue' => (float) $item->revenue,
                'growth' => $growth,
            ];
        });

        // =============================
        // 6️⃣ COMPARATIVA SEMESTRAL (NUEVO)
        // =============================
        $semesterRevenue = Payment::selectRaw('
            CONCAT("S", IF(MONTH(paid_at) <= 6, 1, 2), " ", YEAR(paid_at)) as semester,
            SUM(amount) as revenue,
            COUNT(DISTINCT subscription_id) as subscriptions
        ')
            ->where('status', 'approved')
            ->groupBy('semester')
            ->orderByRaw('YEAR(paid_at), MONTH(paid_at)')
            ->get()
            ->map(fn($row) => [
                'semester' => $row->semester,
                'revenue' => (float) $row->revenue,
                'subscriptions' => (int) $row->subscriptions,
            ]);

        // =============================
        // RESPONSE FINAL
        // =============================
        return $this->success([
            'kpis' => [
                'subscriptions_this_month' => $subscriptionsThisMonth,
                'new_customers' => $newCustomers,
                'cancellations' => $cancellations,
                'revenue_this_month' => (float) $revenueThisMonth,
                'net_growth' => $netGrowth,
            ],
            'charts' => [
                'monthly_revenue' => $monthlyRevenue,
                'growth' => $growth,
                'yearly_revenue' => $yearlyRevenue,
                'plans_distribution' => $plansDistribution,
                'semester_revenue' => $semesterRevenue, // 👈 NUEVO
            ]
        ], 'Dashboard metrics');
    }
}
