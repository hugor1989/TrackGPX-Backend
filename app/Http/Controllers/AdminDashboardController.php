<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Payment;
use App\Models\Plan;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        // =============================
        // Fechas base
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

        $revenueThisMonth = Payment::where('status', 'paid')
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
            ->where('status', 'paid')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'revenue' => (float) $row->revenue,
                'subscriptions' => (int) $row->subscriptions,
            ]);

        // =============================
        // 4️⃣ CRECIMIENTO NETO (ALTAS VS CANCELACIONES)
        // =============================
        $growth = Subscription::selectRaw('
                DATE_FORMAT(created_at, "%Y-%m") as month,
                COUNT(*) as new_subscriptions,
                SUM(status = "cancelled") as cancelled
            ')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'new' => (int) $row->new_subscriptions,
                'cancelled' => (int) $row->cancelled,
                'net' => (int) $row->new_subscriptions - (int) $row->cancelled,
            ]);

        // =============================
        // 5️⃣ INGRESOS ANUALES
        // =============================
        $yearlyRevenue = Payment::selectRaw('
                YEAR(paid_at) as year,
                SUM(amount) as revenue
            ')
            ->where('status', 'paid')
            ->groupBy('year')
            ->orderBy('year')
            ->get()
            ->map(fn ($row) => [
                'year' => (int) $row->year,
                'revenue' => (float) $row->revenue,
            ]);

        // =============================
        // RESPONSE FINAL
        // =============================
        return response()->json([
            'kpis' => [
                'subscriptions_this_month' => $subscriptionsThisMonth,
                'new_customers' => $newCustomers,
                'cancellations' => $cancellations,
                'revenue_this_month' => (float) $revenueThisMonth,
                'net_growth' => $netGrowth,
            ],
            'plans_distribution' => $plansDistribution,
            'monthly_revenue' => $monthlyRevenue,
            'growth' => $growth,
            'yearly_revenue' => $yearlyRevenue,
        ]);
    }
}
