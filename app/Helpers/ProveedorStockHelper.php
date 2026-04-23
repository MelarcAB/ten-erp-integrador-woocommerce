<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;

class ProveedorStockHelper
{
    /**
     * @return array{
     *   by_sku:array<string,array{stock:int,price:float|null,price_string:string|null}>,
     *   by_ean:array<string,array{stock:int,price:float|null,price_string:string|null}>,
     *   processed:int,
     *   invalid:int
     * }
     */
    public static function load(string $url): array
    {
        if (trim($url) === '') {
            throw new \RuntimeException('La URL del proveedor está vacía');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'stock_prov_');
        if ($tmp === false) {
            throw new \RuntimeException('No se pudo crear archivo temporal para proveedor');
        }

        try {
            $response = Http::timeout(60)->get($url);
            if (!$response->successful()) {
                throw new \RuntimeException('Error al descargar CSV proveedor. HTTP ' . $response->status());
            }

            if (file_put_contents($tmp, $response->body()) === false) {
                throw new \RuntimeException('No se pudo escribir el CSV proveedor en disco');
            }

            $handle = fopen($tmp, 'r');
            if ($handle === false) {
                throw new \RuntimeException('No se pudo abrir el CSV proveedor');
            }

            try {
                $header = fgetcsv($handle, 0, ';');
                if (!is_array($header)) {
                    throw new \RuntimeException('No se pudieron leer los headers del proveedor');
                }

                $map = self::mapHeaders($header);
                if (!isset($map['MODELO'], $map['STOCK'])) {
                    throw new \RuntimeException('Faltan columnas obligatorias proveedor: MODELO, STOCK');
                }

                $hasEan = isset($map['EAN']);
                $hasPvpr = isset($map['PVPR']);
                $bySku = [];
                $byEan = [];
                $processed = 0;
                $invalid = 0;

                while (($row = fgetcsv($handle, 0, ';')) !== false) {
                    $processed++;
                    if (!is_array($row)) {
                        $invalid++;
                        continue;
                    }

                    $sku = self::getCol($row, $map['MODELO']);
                    $ean = $hasEan ? self::getCol($row, $map['EAN']) : '';
                    $stock = self::toInt(self::getCol($row, $map['STOCK']));
                    $priceString = $hasPvpr ? self::toDecimalString(self::getCol($row, $map['PVPR'])) : null;
                    $price = $priceString !== null ? (float) $priceString : null;

                    if ($sku === '' && $ean === '') {
                        $invalid++;
                        continue;
                    }

                    $value = [
                        'stock' => $stock,
                        'price' => $price,
                        'price_string' => $priceString,
                    ];

                    if ($sku !== '') {
                        $bySku[$sku] = $value;
                    }
                    if ($ean !== '') {
                        $byEan[$ean] = $value;
                    }
                }
            } finally {
                fclose($handle);
            }

            return [
                'by_sku' => $bySku,
                'by_ean' => $byEan,
                'processed' => $processed,
                'invalid' => $invalid,
            ];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param array<int,string> $header
     * @return array<string,int>
     */
    private static function mapHeaders(array $header): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $key = strtoupper(trim((string) $h));
            if ($key !== '') {
                $map[$key] = (int) $i;
            }
        }

        return $map;
    }

    private static function getCol(array $row, int $idx): string
    {
        return trim((string) ($row[$idx] ?? ''));
    }

    private static function toInt(string $val): int
    {
        $val = trim($val);
        if ($val === '') {
            return 0;
        }

        return (int) round((float) str_replace(',', '.', $val));
    }

    private static function toDecimalString(string $val): ?string
    {
        $normalized = trim(str_replace([' ', ','], ['', '.'], $val));
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }
}
