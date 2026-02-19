<?php

namespace App\Http\Controllers;

use App\Models\Pedidos;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PedidosController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->string('q'));
        $syncStatus = trim((string) $request->string('sync_status')); // pending|synced|error|disabled
        $wcStatus = trim((string) $request->string('status')); // Woo status: processing|completed|...
        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(10, min(100, $perPage));

        $query = Pedidos::query()->select([
            'id',
            'woocommerce_id',
            'woocommerce_number',
            'woocommerce_order_key',
            'woocommerce_customer_id',
            'cliente_id',
            'status',
            'sync_status',
            'currency',
            'total',
            'total_tax',
            'payment_method_title',
            'wc_date_created',
            'wc_date_paid',
            'ten_codigo',
            'ten_id',
            'ten_last_fetched_at',
            'last_error',
            'updated_at',
        ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('woocommerce_number', 'like', "%{$search}%")
                    ->orWhere('woocommerce_id', 'like', "%{$search}%")
                    ->orWhere('woocommerce_customer_id', 'like', "%{$search}%")
                    ->orWhere('ten_codigo', 'like', "%{$search}%")
                    ->orWhere('ten_id', 'like', "%{$search}%")
                    ->orWhere('payment_method_title', 'like', "%{$search}%");
            });
        }

        if ($syncStatus !== '') {
            $query->where('sync_status', $syncStatus);
        }

        if ($wcStatus !== '') {
            $query->where('status', $wcStatus);
        }

        $query->orderByDesc('wc_date_created')->orderByDesc('updated_at');

        $pedidos = $query->paginate($perPage)->withQueryString();

        $counts = Pedidos::query()
            ->selectRaw('sync_status, COUNT(*) as total')
            ->groupBy('sync_status')
            ->pluck('total', 'sync_status')
            ->toArray();

        $statusCounts = Pedidos::query()
            ->whereNotNull('status')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return Inertia::render('Orders', [
            'filters' => [
                'q' => $search,
                'sync_status' => $syncStatus,
                'status' => $wcStatus,
                'per_page' => $perPage,
            ],
            'filtros' => [
                'q' => $search,
                'sync_status' => $syncStatus,
                'status' => $wcStatus,
                'per_page' => $perPage,
            ],
            'pedidos' => $pedidos,
            'counts' => $counts,
            'statusCounts' => $statusCounts,
        ]);
    }
}
