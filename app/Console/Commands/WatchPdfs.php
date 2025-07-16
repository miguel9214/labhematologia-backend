<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class WatchPdfs extends Command
{
    protected $signature   = 'pdfs:watch';
    protected $description = 'Observa la carpeta remota y, al detectar nuevos PDFs, dispara pdfs:import';

    public function handle()
    {
        // Ruta absoluta a tu folder de PDFs (igual que PDF_REMOTE_ROOT)
        $path = config('filesystems.disks.pdf_remote.root');

        if (! extension_loaded('inotify')) {
            $this->error("La extensión inotify no está instalada o habilitada.");
            return 1;
        }

        $this->info("🕵️‍♂️ Observando cambios en {$path}… (CTRL+C para salir)");

        // Inicializa inotify
        $inotify = inotify_init();
        stream_set_blocking($inotify, false);

        // Observa creación / movimiento / cierre escritura
        inotify_add_watch(
          $inotify,
          $path,
          IN_CREATE | IN_MOVED_TO | IN_CLOSE_WRITE
        );

        while (true) {
            $events = inotify_read($inotify);
            if ($events) {
                foreach ($events as $event) {
                    // Reacción solo si es PDF
                    if (
                      isset($event['name']) &&
                      preg_match('/\.pdf$/i', $event['name'])
                    ) {
                        $this->info("📄 Detectado PDF: {$event['name']} → reimportando…");
                        // Lanza tu comando de importación
                        $proc = new Process(['php', 'artisan', 'pdfs:import']);
                        $proc->setTimeout(300);
                        $proc->run();
                        $this->info(trim($proc->getOutput()));
                    }
                }
            }
            // Pequeña espera para no consumir 100% CPU
            sleep(1);
        }
    }
}
