<?php

namespace App\Integrations\Mappers;

class WooCustomerMapper
{
    /**
     * WC customer -> columnas de tabla clientes (sin direcciones).
     *
     * @param array<string,mixed> $wc
     * @return array<string,mixed>
     */
    public static function toClienteAttributes(array $wc): array
    {
        $int = static fn ($v) => ($v === null || $v === '') ? null : (int) $v;
        $str = static fn ($v) => ($v === null) ? null : trim((string) $v);

        $email = $str($wc['email'] ?? null) ?: $str($wc['billing']['email'] ?? null);

        $first = $str($wc['first_name'] ?? null) ?: $str($wc['billing']['first_name'] ?? null);
        $last  = $str($wc['last_name'] ?? null)  ?: $str($wc['billing']['last_name'] ?? null);

        $phone = $str($wc['billing']['phone'] ?? null);

        $attrs = [
            // Mapeo WC
            'woocommerce_id' => $int($wc['id'] ?? null),

            // Campos “comunes” en tu tabla
            'email'     => $email,
            'nombre'    => $first,
            'apellidos' => $last,
            'telefono'  => $phone,

            // TEN-only (desde WC NO vienen) -> nullables
            'ten_id' => null,
            'ten_codigo' => null,
            'nombre_fiscal' => null,
            'nif' => null,
            'ten_id_direccion_envio' => null,
            'ten_id_grupo_clientes' => null,
            'ten_regimen_impuesto' => null,
            'ten_id_tarifa' => null,
            'ten_vendedor' => null,
            'ten_forma_pago' => null,
            'telefono2' => null,
            'web' => null,
            'ten_calculo_iva_factura' => null,

            // ⚠️ IMPORTANTÍSIMO: en DB son NOT NULL (boolean default false)
            // Si no vienen de WC -> false
            'ten_persona' => false,
            'ten_enviar_emails' => false,
            'ten_consentimiento_datos' => false,
        ];

        // Limpieza strings vacíos -> null (pero NO tocar booleans)
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
     * @param array<string,mixed> $attrs
     */
    public static function hashFromAttributes(array $attrs): string
    {
        $copy = $attrs;

        unset(
            $copy['sync_status'],
            $copy['last_error'],
            $copy['ten_last_fetched_at'],
            $copy['created_at'],
            $copy['updated_at'],
            $copy['deleted_at']
        );

        ksort($copy);

        return hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
