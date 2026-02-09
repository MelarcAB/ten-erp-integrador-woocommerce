<?php

namespace App\Integrations\Mappers;

class TenCategoryMapper
{
    /**
     * Normaliza una categoría de TEN a columnas de tu tabla categorias.
     *
     * @param array<string, mixed> $ten
     * @return array<string, mixed>
     */
    public static function toCategoriaAttributes(array $ten): array
    {
        $int = static fn ($v) => ($v === null || $v === '') ? null : (int) $v;
        $str = static fn ($v) => ($v === null) ? null : trim((string) $v);

        // Mantener decimales como string para no introducir floating point noise
        $dec = static function ($v): ?string {
            if ($v === null) return null;
            $v = trim((string) $v);
            return $v === '' ? null : $v;
        };

        // Fechas vienen como "YYYY-MM-DD HH:MM:SS" (string). DB casteará si es TIMESTAMP/DATETIME.
        $dt = static function ($v): ?string {
            if ($v === null) return null;
            $v = trim((string) $v);
            return $v === '' ? null : $v;
        };

        $attrs = [
            // --- Identificadores TEN ---
            'ten_id_numero'       => $int($ten['IdNumero'] ?? null),
            'ten_codigo'          => $str($ten['Codigo'] ?? null),

            // --- Datos TEN ---
            'ten_nombre'          => $str($ten['Nombre'] ?? null),
            'ten_web_nombre'      => $str($ten['WebNombre'] ?? null),
            'ten_categoria_padre' => $int($ten['CategoriaPadre'] ?? null),

            'ten_ultimo_usuario'  => $int($ten['tenUltimoUsuario'] ?? null),
            'ten_ultimo_cambio'   => $dt($ten['tenUltimoCambio'] ?? null),
            'ten_alta_usuario'    => $int($ten['tenAltaUsuario'] ?? null),
            'ten_alta_fecha'      => $dt($ten['tenAltaFecha'] ?? null),

            'ten_web_sincronizar' => (int)($ten['WebSincronizar'] ?? 0) === 1,
            'ten_bloqueado'       => (int)($ten['tenBloqueado'] ?? 0) === 1,

            'ten_usr_peso'        => $dec($ten['USR_Peso'] ?? null),
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
     * Hash estable para detectar cambios reales.
     *
     * @param array<string, mixed> $attrs
     */
    public static function hashFromAttributes(array $attrs): string
    {
        $copy = $attrs;

        // Nunca metas campos “operacionales” en el hash
        unset(
            $copy['woocommerce_categoria_id'],
            $copy['woocommerce_categoria_padre_id'],
            $copy['enable_sync'],
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
