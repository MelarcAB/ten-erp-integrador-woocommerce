<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ProdExportProductosCsv extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prod-export-productos-csv';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Exporta products.json a CSV (products.cs), descartando bloqueados.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $inputPath = storage_path('app/private/products.json');
        $outputPath = storage_path('app/private/products.cs');

        if (!is_file($inputPath)) {
            $this->error("No existe el archivo de entrada: {$inputPath}");
            return self::FAILURE;
        }

        $raw = file_get_contents($inputPath);
        if ($raw === false) {
            $this->error("No se pudo leer el archivo: {$inputPath}");
            return self::FAILURE;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $err = json_last_error_msg();
            $this->error("JSON inválido en {$inputPath}: {$err}");
            return self::FAILURE;
        }

        $total = count($data);
        $skippedBlocked = 0;
        $products = [];
        $header = [];
        $headerSet = [];

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $isBlocked = isset($item['Bloqueado']) && (int) $item['Bloqueado'] === 1;
            if ($isBlocked) {
                $skippedBlocked++;
                continue;
            }
            $products[] = $item;
            foreach ($item as $key => $_) {
                if (!isset($headerSet[$key])) {
                    $headerSet[$key] = true;
                    $header[] = $key;
                }
            }
        }

        $fh = fopen($outputPath, 'w');
        if ($fh === false) {
            $this->error("No se pudo crear el archivo: {$outputPath}");
            return self::FAILURE;
        }

        if (!empty($header)) {
            fputcsv($fh, $header);
        }

        $written = 0;
        foreach ($products as $item) {
            $row = [];
            foreach ($header as $key) {
                $value = $item[$key] ?? '';
                if ($key === 'Categorias') {
                    $row[] = $this->normalizeCategorias($value);
                    continue;
                }
                $row[] = $this->normalizeValue($value);
            }
            fputcsv($fh, $row);
            $written++;
        }

        fclose($fh);

        $this->info("Leídos: {$total} | bloqueados: {$skippedBlocked} | escritos: {$written}");
        $this->info("Archivo generado: {$outputPath}");

        return self::SUCCESS;
    }

    private function normalizeCategorias(mixed $value): string
    {
        if (!is_array($value)) {
            return $this->normalizeValue($value);
        }
        $parts = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                if (array_key_exists('IdCategoria', $item)) {
                    $parts[] = (string) $item['IdCategoria'];
                    continue;
                }
                if (array_key_exists('id', $item)) {
                    $parts[] = (string) $item['id'];
                    continue;
                }
                if (array_key_exists('Id', $item)) {
                    $parts[] = (string) $item['Id'];
                    continue;
                }
                $first = null;
                foreach ($item as $v) {
                    $first = $v;
                    break;
                }
                if ($first !== null) {
                    $parts[] = $this->normalizeValue($first);
                }
                continue;
            }
            $parts[] = $this->normalizeValue($item);
        }

        $parts = array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));
        return implode(',', $parts);
    }

    private function normalizeValue(mixed $value): string
    {
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json === false ? '' : $json;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }
        return (string) $value;
    }
}
