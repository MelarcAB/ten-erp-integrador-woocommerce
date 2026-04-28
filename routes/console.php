<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$runProdDailySync = function (): void {
    $marker = '[SCHEDULE_PROD_SYNC v1]';
    $commands = [
        'app:prod-sync-categorias',
        'app:prod-sync-fabricantes',
        'app:prod-sync-productos',
        'app:prod-sync-img',
        'app:prod-sync-clients',
        'app:prod-sync-pedidos',
    ];

    Log::info($marker . ' start', ['commands' => $commands]);

    foreach ($commands as $command) {
        Log::info($marker . ' command start', ['command' => $command]);
        $exitCode = Artisan::call($command);
        Log::info($marker . ' command done', [
            'command' => $command,
            'exit_code' => $exitCode,
            'output' => trim(Artisan::output()),
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException("Fallo en scheduler al ejecutar {$command} (exit {$exitCode})");
        }
    }

    Log::info($marker . ' done');
};

Schedule::call($runProdDailySync)
    ->name('prod-sync-0500')
    ->dailyAt('05:00');

Schedule::call($runProdDailySync)
    ->name('prod-sync-1300')
    ->dailyAt('13:00');
