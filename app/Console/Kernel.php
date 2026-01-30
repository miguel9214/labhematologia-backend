<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\ImportPdfs;
use App\Console\Commands\WatchPdfs;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * Para que el cron funcione, en el servidor debe estar configurado:
     *   * * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
     * (en Windows: crear una tarea programada que ejecute cada minuto:
     *   php C:\laragon\www\labhematologia-backend\artisan schedule:run
     */
    protected function schedule(Schedule $schedule): void
    {
        // Cada 15 min: indexa solo PDFs modificados en la última hora (rápido, mantiene al día)
        $schedule->command('pdfs:import --since=60')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10);

        // Una vez al día (2:00): importación completa de todas las carpetas y años
        $schedule->command('pdfs:import')
            ->dailyAt('02:00')
            ->withoutOverlapping(120);
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }

    protected $commands = [
        ImportPdfs::class,
        WatchPdfs::class,
    ];

}
