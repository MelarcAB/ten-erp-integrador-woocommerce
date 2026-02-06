<?php

namespace App\Integrations\Mappers;

class TenProductMapper
{
    /**
     * @param array<string, mixed> $ten
     * @return array<string, mixed>
     */
    public static function toProductoAttributes(array $ten): array
    {
        $int = static fn ($v) => ($v === null || $v === '') ? null : (int) $v;
        $str = static fn ($v) => ($v === null) ? null : trim((string) $v);

        // Mantener números como string para no introducir floating point noise
        $dec = static function ($v): ?string {
            if ($v === null) return null;
            $v = trim((string) $v);
            return $v === '' ? null : $v;
        };

        $attrs = [
            'ten_id'                     => $int($ten['Id'] ?? null),
            'ten_codigo'                 => $str($ten['Codigo'] ?? null),
            'ten_id_grupo_productos'     => $int($ten['IdGrupoProductos'] ?? null),

            'ten_web_nombre'             => $str($ten['Web-Nombre'] ?? null),
            'ten_web_descripcion_corta'  => $str($ten['Web-DescripcionCorta'] ?? null),
            'ten_web_descripcion_larga'  => $str($ten['Web-DescripcionLarga'] ?? null),
            'ten_web_control_stock'      => (int)($ten['Web-ControlStock'] ?? 0) === 1,

            'ten_precio'                 => $dec($ten['Precio'] ?? null),
            'ten_bloqueado'              => (int)($ten['Bloqueado'] ?? 0) === 1,

            'ten_fabricante'             => $int($ten['Fabricante'] ?? null),
            'ten_referencia'             => $str($ten['Referencia'] ?? null),
            'ten_catalogo'               => $str($ten['Catalogo'] ?? null),
            'ten_prioridad'              => (int)($ten['Prioridad'] ?? 0),

            'ten_fraccionar_formato_venta' => $str($ten['FraccionarFormatoVenta'] ?? null),

            'ten_peso'                   => $dec($ten['Peso'] ?? null),
            'ten_porc_impost'            => $dec($ten['PorcImpost'] ?? null),
            'ten_porc_recargo'           => $dec($ten['PorcRecargo'] ?? null),

            'ten_ean'                    => $str($ten['EAN'] ?? $ten['Ean'] ?? null),
            'ten_upc'                    => $str($ten['UPC'] ?? $ten['Upc'] ?? null),
        ];

        // Limpieza: strings vacíos -> null
        foreach ($attrs as $k => $v) {
            if (is_string($v) && $v === '') {
                $attrs[$k] = null;
            }
        }

        return $attrs;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public static function hashFromAttributes(array $attrs): string
    {
        $copy = $attrs;

        // Nunca metas campos “operacionales” en el hash (si los hubiera)
        unset(
            $copy['ten_last_fetched_at'],
            $copy['sync_status'],
            $copy['last_error'],
            $copy['created_at'],
            $copy['updated_at'],
            $copy['deleted_at']
        );

        ksort($copy);

        return hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
