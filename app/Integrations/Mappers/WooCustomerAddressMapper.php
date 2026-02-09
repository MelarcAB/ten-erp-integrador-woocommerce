<?php

namespace App\Integrations\Mappers;

class WooCustomerAddressMapper
{
    /**
     * Devuelve 0, 1 o 2 direcciones (billing / shipping) a partir del customer completo.
     *
     * @param array<string,mixed> $wcCustomer
     * @return array<int, array<string,mixed>>
     */
    public static function toDirecciones(array $wcCustomer): array
    {
        $dirs = [];

        if (!empty($wcCustomer['billing']) && is_array($wcCustomer['billing'])) {
            $dirs[] = self::mapOne($wcCustomer, $wcCustomer['billing'], 'billing');
        }

        if (!empty($wcCustomer['shipping']) && is_array($wcCustomer['shipping'])) {
            $dirs[] = self::mapOne($wcCustomer, $wcCustomer['shipping'], 'shipping');
        }

        return $dirs;
    }

    /**
     * Igual que toDirecciones(), pero añade el cliente_id (relación a tu BD).
     *
     * @param array<string,mixed> $wcCustomer
     * @return array<int, array<string,mixed>>
     */
    public static function toDireccionesForCliente(array $wcCustomer, int $clienteId): array
    {
        $dirs = self::toDirecciones($wcCustomer);

        foreach ($dirs as &$d) {
            $d['cliente_id'] = $clienteId;
        }

        return $dirs;
    }

    /**
     * @param array<string,mixed> $wc
     * @param array<string,mixed> $addr
     * @return array<string,mixed>
     */
    private static function mapOne(array $wc, array $addr, string $tipo): array
    {
        $attrs = [
            'woocommerce_customer_id' => (int)($wc['id'] ?? 0),
            'tipo' => $tipo,

            'first_name' => $addr['first_name'] ?? null,
            'last_name'  => $addr['last_name'] ?? null,
            'company'    => $addr['company'] ?? null,
            'address_1'  => $addr['address_1'] ?? null,
            'address_2'  => $addr['address_2'] ?? null,
            'city'       => $addr['city'] ?? null,
            'postcode'   => $addr['postcode'] ?? null,
            'state'      => $addr['state'] ?? null,
            'country'    => $addr['country'] ?? null,
            'email'      => $addr['email'] ?? ($wc['email'] ?? null),
            'phone'      => $addr['phone'] ?? null,
        ];

        foreach ($attrs as $k => $v) {
            if (is_string($v) && trim($v) === '') {
                $attrs[$k] = null;
            }
        }

        return $attrs;
    }

    /**
     * Hash estable para detectar cambios.
     *
     * @param array<string,mixed> $attrs
     */
    public static function hashFromAttributes(array $attrs): string
    {
        unset(
            $attrs['sync_status'],
            $attrs['last_error'],
            $attrs['ten_last_fetched_at'],
            $attrs['ten_hash'],
            $attrs['created_at'],
            $attrs['updated_at'],
            $attrs['deleted_at']
        );

        ksort($attrs);

        return hash(
            'sha256',
            json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
