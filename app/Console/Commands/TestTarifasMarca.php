<?php

namespace App\Console\Commands;

use App\Helpers\DescuentosMarcaHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestTarifasMarca extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-tarifas-marca';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Hace un GET al endpoint de tarifas por marca y muestra la respuesta en consola';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $marker = '[TEST_TARIFAS_MARCA v1]';

        $this->line($marker . ' start');
        Log::info($marker . ' start');

        try {
            $descuentos = DescuentosMarcaHelper::getDescuentos();
        } catch (Throwable $e) {
            $this->error('Error haciendo GET: ' . $e->getMessage());
            Log::error($marker . ' request failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $encoded = json_encode($descuentos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $this->error('No se pudo serializar la respuesta JSON');
            return self::FAILURE;
        }
        $this->line($encoded);

        Log::info($marker . ' done', ['count' => count($descuentos)]);

        return self::SUCCESS;
    }
}
