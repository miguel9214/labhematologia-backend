<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\PdfDocument;
use Carbon\Carbon;

class ImportPdfs extends Command
{
   protected $signature = 'pdfs:import {--year=} {--since= : Minutos hacia atrás} {--dry}';
    protected $description = 'Indexa PDFs desde el disco pdf_remote (soporta Mes/Año, Día/Mes y subcarpetas intermedias)';

    protected array $months = [
        'enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,
        'julio'=>7,'agosto'=>8,'septiembre'=>9,'octubre'=>10,'noviembre'=>11,'diciembre'=>12,
    ];

public function handle()
{
    $disk = Storage::disk('pdf_remote');
    $root = config('filesystems.disks.pdf_remote.root');

    $this->info("Escaneando PDFs en: {$root}");

    // Flags/opts
    $yearFilter = $this->option('year') ? (int) $this->option('year') : null;
    $sinceMin   = $this->option('since') ? (int) $this->option('since') : null;
    $dry        = (bool) $this->option('dry');
    $cutoff     = $sinceMin ? Carbon::now()->subMinutes($sinceMin)->timestamp : null;

    // Mapa de meses (soporta acentos y 'setiembre')
    $months = [
        'enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,
        'julio'=>7,'agosto'=>8,'septiembre'=>9,'setiembre'=>9,'octubre'=>10,
        'noviembre'=>11,'diciembre'=>12,
    ];

    $files = $disk->allFiles();
    $this->info('Total de archivos encontrados: ' . count($files));

    $ok = 0; $skipped = 0; $bad = 0;

    foreach ($files as $file) {
        // Solo PDFs
        if (!preg_match('/\.pdf$/i', $file)) { $skipped++; continue; }

        // Filtro por "recientes"
        if ($cutoff) {
            try {
                $mtime = $disk->lastModified($file);
                if ($mtime < $cutoff) { $skipped++; continue; }
            } catch (\Throwable $e) {
                // si falla, seguimos sin filtrar por tiempo
            }
        }

        $relative = str_replace('\\', '/', trim($file, '/'));
        $partsAll = explode('/', $relative);
        if (count($partsAll) < 2) {
            $bad++;
            $this->warn("Ruta inesperada (muy corta): {$relative}");
            continue;
        }

        $filename = array_pop($partsAll);         // último segmento (archivo)
        $parts    = array_values($partsAll);      // solo directorios

        $year = null; $month = null; $day = null;

        // --- Caso A: .../YYYY/MM/DD/archivo.pdf (tres últimos dir numéricos)
        if (count($parts) >= 3) {
            $a = $parts[count($parts)-3];
            $b = $parts[count($parts)-2];
            $c = $parts[count($parts)-1];

            if (preg_match('/^\d{4}$/', $a) && preg_match('/^\d{1,2}$/', $b) && preg_match('/^\d{1,2}$/', $c)) {
                $year  = (int) $a;
                $month = (int) $b;
                $day   = (int) $c;
            }
        }

        // --- Caso B: "MES YYYY/ DD MES /(opcional subcarpeta)/ archivo.pdf"
        if ($year === null && count($parts) >= 2) {
            $mesAno = $parts[0]; // p.ej. "JULIO 2025"
            $diaMes = $parts[1]; // p.ej. "19 JULIO"

            if (
                preg_match('/^\s*([A-Za-zÁÉÍÓÚÜÑ]+)\s+(\d{4})\s*$/u', $mesAno, $m1) &&
                preg_match('/^\s*(\d{1,2})\s+[A-Za-zÁÉÍÓÚÜÑ]+\s*$/u', $diaMes, $m2)
            ) {
                $monthName = mb_strtolower($m1[1], 'UTF-8');
                $yearCand  = (int) $m1[2];
                $dayCand   = (int) $m2[1];

                if (isset($months[$monthName])) {
                    $year  = $yearCand;
                    $month = (int) $months[$monthName];
                    $day   = $dayCand;
                }
            }
        }

        // Validación estricta de rangos
        $nowYear = (int) date('Y');
        if (
            !$year || !$month || !$day ||
            $year < 1990 || $year > $nowYear + 1 ||
            $month < 1 || $month > 12 ||
            $day < 1 || $day > 31
        ) {
            $bad++;
            $this->warn("No se pudo inferir fecha válida: {$relative}");
            continue;
        }

        // Filtro por año si se solicitó
        if ($yearFilter && (int) $year !== $yearFilter) { $skipped++; continue; }

        // Escritura (o dry-run)
        if ($dry) {
            $this->line("[dry] {$relative} → {$year}-{$month}-{$day}");
            $ok++;
            continue;
        }

        PdfDocument::updateOrCreate(
            ['path' => $relative],
            ['name' => $filename, 'year' => $year, 'month' => $month, 'day' => $day]
        );
        $ok++;
    }

    $this->info("OK: {$ok} | Omitidos: {$skipped} | Con errores: {$bad}");
    return self::SUCCESS;
}


    /**
     * Intenta extraer [year, month, day] de una lista de directorios.
     * Soporta:
     *  - [ 'JULIO 2025', '19 JULIO', (opcional '123456') ]
     *  - [ '2025', '07', '19' ]
     *  - combinaciones similares
     */
    protected function extractYmd(array $dirs): array
    {
        $year = $month = $day = null;

        // Normaliza (quita espacios extra y acentos para matching)
        $norm = fn(string $s) => preg_replace('/\s+/', ' ', $this->unaccent(mb_strtolower(trim($s),'UTF-8')));

        $N = count($dirs);
        if ($N === 0) return [null,null,null];

        // --- Casos 1 y 2: patrón español con posible subcarpeta intermedia
        // Esperado desde el final:
        //   ... / (opcional SUB) / "DD MES" / "MES AAAA"
        //   ... /            "DD MES" / "MES AAAA"
        if ($N >= 2) {
            $last   = $norm($dirs[$N-1]);
            $mid    = $norm($dirs[$N-2]);
            $before = $N >= 3 ? $norm($dirs[$N-3]) : null;

            // Si hay subcarpeta intermedia (normalmente numérica), muevo las ventanas
            // Caso con subcarpeta: last = SUB, mid = "DD MES", before = "MES AAAA"
            if ($before && $this->looksDiaMes($mid) && $this->looksMesAno($before)) {
                [$day, $monthFromDM] = $this->parseDiaMes($mid);
                [$monthFromMA, $year] = $this->parseMesAno($before);
                $month = $monthFromDM ?: $monthFromMA;
                return [$year,$month,$day];
            }

            // Caso sin subcarpeta: last = "DD MES", mid = "MES AAAA"
            if ($this->looksDiaMes($last) && $this->looksMesAno($mid)) {
                [$day, $monthFromDM] = $this->parseDiaMes($last);
                [$monthFromMA, $year] = $this->parseMesAno($mid);
                $month = $monthFromDM ?: $monthFromMA;
                return [$year,$month,$day];
            }
        }

        // --- Caso 3: todo numérico tipo AAAA/MM/DD (o con subcarpeta)
        if ($N >= 3) {
            // Tomamos los tres últimos directorios que sean dígitos (ignorando subcarpetas no numéricas)
            $digits = [];
            for ($i = $N-1; $i >= 0; $i--) {
                if (ctype_digit($dirs[$i])) $digits[] = $dirs[$i];
                if (count($digits) === 3) break;
            }
            if (count($digits) === 3) {
                $digits = array_reverse($digits); // en orden original
                // Heurística: un token de 4 dígitos es año
                foreach ($digits as $tok) {
                    if (strlen($tok) === 4) { $year = (int)$tok; }
                }
                // Extrae mes y día del resto
                $rest = array_values(array_filter($digits, fn($t)=> (int)$t !== $year));
                if (count($rest) === 2) {
                    $a=(int)$rest[0]; $b=(int)$rest[1];
                    // Decide cuál es mes/día por rango
                    if ($a>=1 && $a<=12 && $b>=1 && $b<=31) { $month=$a; $day=$b; }
                    elseif ($b>=1 && $b<=12 && $a>=1 && $a<=31) { $month=$b; $day=$a; }
                }
                if ($year && $month && $day) return [$year,$month,$day];
            }
        }

        // --- Búsqueda suelta: escanea todos por "MES AAAA" y "DD MES"
        $foundMA = $foundDM = null;
        foreach ($dirs as $d) {
            $s = $norm($d);
            if (!$foundMA && $this->looksMesAno($s)) $foundMA = $s;
            if (!$foundDM && $this->looksDiaMes($s)) $foundDM = $s;
        }
        if ($foundMA) {
            [$m,$y] = $this->parseMesAno($foundMA);
            $month = $m; $year = $y;
        }
        if ($foundDM) {
            [$d,$m2] = $this->parseDiaMes($foundDM);
            $day = $d;
            if (!$month) $month = $m2;
        }

        return [$year,$month,$day];
    }

    protected function looksMesAno(string $s): bool
    {
        // "julio 2025"
        return (bool)preg_match('/^[[:alpha:]]+\s+\d{4}$/u', $s);
    }
    protected function looksDiaMes(string $s): bool
    {
        // "19 julio"
        return (bool)preg_match('/^\d{1,2}\s+[[:alpha:]]+$/u', $s);
    }

    protected function parseMesAno(string $s): array
    {
        // "julio 2025" -> [7, 2025]
        [$mes,$ano] = preg_split('/\s+/', $s, 2);
        $mes = $this->monthFromName($mes);
        return [$mes, (int)$ano];
    }

    protected function parseDiaMes(string $s): array
    {
        // "19 julio" -> [19, 7]
        [$d,$mes] = preg_split('/\s+/', $s, 2);
        return [(int)$d, $this->monthFromName($mes)];
    }

    protected function monthFromName(string $name): ?int
    {
        $n = $this->unaccent(mb_strtolower(trim($name),'UTF-8'));
        return $this->months[$n] ?? null;
    }

    protected function unaccent(string $s): string
    {
        $map = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n'];
        return strtr($s, $map);
    }
}
