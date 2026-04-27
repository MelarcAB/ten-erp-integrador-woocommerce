<?php

namespace App\Console\Commands\Concerns;

trait WritesDailyEntityLog
{
    private $dailyEntityLogHandle = null;

    private function initDailyEntityLog(string $entity): void
    {
        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $filename = 'log_' . $entity . '_' . now()->format('Ymd') . '.log';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        $this->dailyEntityLogHandle = @fopen($path, 'a');
    }

    private function writeDailyEntityLog(string $line): void
    {
        if (!is_resource($this->dailyEntityLogHandle)) return;

        $ts = now()->format('Y-m-d H:i:s');
        @fwrite($this->dailyEntityLogHandle, '[' . $ts . '] ' . $line . PHP_EOL);
    }

    private function closeDailyEntityLog(): void
    {
        if (is_resource($this->dailyEntityLogHandle)) {
            fclose($this->dailyEntityLogHandle);
            $this->dailyEntityLogHandle = null;
        }
    }
}
