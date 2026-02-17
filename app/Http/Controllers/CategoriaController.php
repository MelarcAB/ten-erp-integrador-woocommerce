<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CategoriaController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->string('q'));
        $status = trim((string) $request->string('status')); // pending|synced|error|disabled
        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(10, min(100, $perPage));

        $query = Categoria::query()
            ->select([
                'ten_id_numero',
                'ten_codigo',
                'ten_nombre',
                'ten_web_nombre',
                'ten_categoria_padre',
                'woocommerce_categoria_id',
                'woocommerce_categoria_padre_id',
                'sync_status',
                'enable_sync',
                'ten_web_sincronizar',
                'ten_bloqueado',
                'ten_ultimo_cambio',
                'ten_last_fetched_at',
                'last_error',
                'updated_at',
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('ten_nombre', 'like', "%{$search}%")
                    ->orWhere('ten_web_nombre', 'like', "%{$search}%")
                    ->orWhere('ten_codigo', 'like', "%{$search}%")
                    ->orWhere('woocommerce_categoria_id', 'like', "%{$search}%");
            });
        }

        if ($status !== '') {
            $query->where('sync_status', $status);
        }

        $query->orderByDesc('updated_at');

        $categorias = $query->paginate($perPage)->withQueryString();

        // Totales por estado (para badges)
        $counts = Categoria::query()
            ->selectRaw('sync_status, COUNT(*) as total')
            ->groupBy('sync_status')
            ->pluck('total', 'sync_status')
            ->toArray();

        return Inertia::render('Categories', [
            // compat: algunas vistas esperan filtros
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
            'categorias' => $categorias,
            'counts' => $counts,
        ]);
    }
}
