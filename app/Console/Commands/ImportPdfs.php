<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Models\PdfDocument;

class ImportPdfs extends Command
{
    protected $signature   = 'pdfs:import';
    protected $description = 'Indexa todos los PDFs en el disco remoto (soporta estructura de 3 o 4 niveles)';

    public function handle()
    {
        $this->info('Escaneando PDFs…');
        $files = Storage::disk('pdf_remote')->allFiles();
        $this->info("Archivos encontrados: " . count($files));

        // Mapeo de meses en español a número
        $months = [
            'enero'=>1, 'febrero'=>2, 'marzo'=>3, 'abril'=>4,
            'mayo'=>5, 'junio'=>6, 'julio'=>7, 'agosto'=>8,
            'septiembre'=>9, 'octubre'=>10, 'noviembre'=>11,
            'diciembre'=>12,
        ];

        foreach ($files as $file) {
            // solo PDFs
            if (! Str::endsWith($file, '.pdf')) {
                continue;
            }

            $relative = str_replace('\\', '/', trim($file, '/'));
            $segments = explode('/', $relative);
            $count    = count($segments);

            // Nombre del archivo al final
            $filename = array_pop($segments);

            // Variables a rellenar
            $year   = null;
            $month  = null;
            $day    = null;

            if ($count >= 4) {
                // Estructura Año/Mes/Día/Archivo
                // Tomamos los 3 segmentos anteriores al nombre
                $day      = (int) array_pop($segments);
                $month    = (int) array_pop($segments);
                $year     = (int) array_pop($segments);
            } elseif ($count === 3) {
                // Estructura Mes Año / Día Mes / Archivo
                [$mesAno, $diaMes] = $segments;

                // Parsear año y mes
                $pos = strrpos($mesAno, ' ');
                if ($pos === false) {
                    $this->warn("Formato inesperado en carpeta 'Mes Año': {$mesAno}");
                    continue;
                }
                $monthName = mb_strtolower(substr($mesAno, 0, $pos), 'UTF-8');
                $year      = (int) substr($mesAno, $pos + 1);

                // Parsear día (número)
                $partsDia = explode(' ', $diaMes, 2);
                $day      = (int) $partsDia[0];

                // Traducir nombre de mes a número
                if (! isset($months[$monthName])) {
                    $this->warn("Mes desconocido: {$monthName}");
                    continue;
                }
                $month = $months[$monthName];
            } else {
                // Omitimos rutas demasiado cortas
                $this->warn("Ruta ignorada (niveles insuficientes): {$relative}");
                continue;
            }

            // Finalmente, guardamos o actualizamos
            PdfDocument::updateOrCreate(
                ['path' => $relative],
                [
                    'name'  => $filename,
                    'year'  => $year,
                    'month' => $month,
                    'day'   => $day,
                ]
            );
            $this->info("Importado: {$relative} → {$year}-{$month}-{$day}");
        }

        $this->info('¡Indexación completada!');
    }
}
