<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductoController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->string('q'));
        $status = trim((string) $request->string('status')); // pending|synced|error|disabled
        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(10, min(100, $perPage));

        $query = Producto::query()
            ->select([
                'id',
                'ten_id',
                'ten_codigo',
                'ten_web_nombre',
                'woocommerce_id',
                'woocommerce_sku',
                'ten_precio',
                'ten_web_control_stock',
                'ten_bloqueado',
                'sync_status',
                'ten_last_fetched_at',
                'last_error',
                'updated_at',
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('ten_web_nombre', 'like', "%{$search}%")
                    ->orWhere('ten_codigo', 'like', "%{$search}%")
                    ->orWhere('woocommerce_sku', 'like', "%{$search}%")
                    ->orWhere('ten_id', 'like', "%{$search}%")
                    ->orWhere('woocommerce_id', 'like', "%{$search}%");
            });
        }

        if ($status !== '') {
            $query->where('sync_status', $status);
        }

        $query->orderByDesc('updated_at');

        $productos = $query->paginate($perPage)->withQueryString();

        $counts = Producto::query()
            ->selectRaw('sync_status, COUNT(*) as total')
            ->groupBy('sync_status')
            ->pluck('total', 'sync_status')
            ->toArray();

        return Inertia::render('Products', [
            'filtros' => [
                'q' => $search,
                'status' => $status,
                'per_page' => $perPage,
            ],
            'filters' => [
                'q' => $search,
                'status' => $status,
                'per_page' => $perPage,
            ],
            'productos' => $productos,
            'counts' => $counts,
        ]);
    }
}
