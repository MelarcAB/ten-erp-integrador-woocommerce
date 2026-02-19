<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->string('q'));
        $status = trim((string) $request->string('status')); // pending|synced|error|disabled
        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(10, min(100, $perPage));

        $query = Cliente::query()
            ->select([
                'woocommerce_id',
                'ten_id',
                'ten_codigo',
                'email',
                'nombre',
                'apellidos',
                'nombre_fiscal',
                'nif',
                'telefono',
                'telefono2',
                'sync_status',
                'ten_persona',
                'ten_enviar_emails',
                'ten_consentimiento_datos',
                'ten_last_fetched_at',
                'last_error',
                'updated_at',
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('apellidos', 'like', "%{$search}%")
                    ->orWhere('nombre_fiscal', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('nif', 'like', "%{$search}%")
                    ->orWhere('ten_codigo', 'like', "%{$search}%")
                    ->orWhere('ten_id', 'like', "%{$search}%")
                    ->orWhere('woocommerce_id', 'like', "%{$search}%");
            });
        }

        if ($status !== '') {
            $query->where('sync_status', $status);
        }

        $query->orderByDesc('updated_at');

        $clientes = $query->paginate($perPage)->withQueryString();

        $counts = Cliente::query()
            ->selectRaw('sync_status, COUNT(*) as total')
            ->groupBy('sync_status')
            ->pluck('total', 'sync_status')
            ->toArray();

        return Inertia::render('Clients', [
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
            'clientes' => $clientes,
            'counts' => $counts,
        ]);
    }
}
