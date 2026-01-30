<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\PdfDocument;
use Carbon\Carbon;

class ImportPdfs extends Command
{
   protected $signature = 'pdfs:import {--year=} {--since= : Minutos hacia atrás} {--today : Solo archivos modificados hoy} {--dry}';
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
    $todayOnly  = (bool) $this->option('today');
    $dry        = (bool) $this->option('dry');
    if ($todayOnly) {
        $cutoff = Carbon::today()->startOfDay()->timestamp; // solo archivos modificados hoy
        $this->info('Modo: solo archivos del día de hoy.');
    } else {
        $cutoff = $sinceMin ? Carbon::now()->subMinutes($sinceMin)->timestamp : null;
    }

    // Para poda por ruta: solo entrar en carpetas cuya fecha (en el nombre) esté en la ventana
    $windowDays = [];
    if ($cutoff !== null) {
        $start = Carbon::createFromTimestamp($cutoff)->startOfDay();
        $end = Carbon::now();
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $windowDays[] = [(int) $d->format('Y'), (int) $d->format('n'), (int) $d->format('j')];
        }
    }

    // Mapa de meses (soporta acentos y 'setiembre')
    $months = [
        'enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,
        'julio'=>7,'agosto'=>8,'septiembre'=>9,'setiembre'=>9,'octubre'=>10,
        'noviembre'=>11,'diciembre'=>12,
    ];

    // Acceso directo por ruta YYYY/MM/DD cuando hay --today o --since (evita escanear todo el árbol en red)
    $useNativeFiles = false;
    $files = [];
    $useTargetedScan = $cutoff !== null && ($todayOnly || ($sinceMin !== null && $sinceMin <= 7 * 24 * 60)); // hoy o últimos 7 días
    if ($useTargetedScan && $root) {
        $start = $todayOnly ? Carbon::today() : Carbon::now()->subMinutes($sinceMin)->startOfDay();
        $end = Carbon::now();
        $datesForTargeted = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $datesForTargeted[] = $d->copy();
        }
        $files = $this->scanTargetedDates($root, $datesForTargeted);
        $useNativeFiles = true;
        if (count($files) > 0) {
            $this->info('Modo acceso directo (carpetas YYYY/MM/DD): ' . count($files) . ' archivos.');
        }
    }

    if (count($files) === 0) {
        if ($useTargetedScan) {
            $this->warn('No se encontraron carpetas YYYY/MM/DD en las fechas indicadas; escaneando árbol completo.');
        }
        try {
            $files = $disk->allFiles();
        } catch (\Throwable $e) {
            $this->warn('Disco Flysystem falló (' . $e->getMessage() . '), intentando listar con PHP nativo.');
            $files = $this->allFilesNative($root, $cutoff, $windowDays);
            $useNativeFiles = true;
        }
    }

    $totalFiles = count($files);
    $this->info('Total de archivos encontrados: ' . $totalFiles);

    // En red UNC, si ya listamos muchos archivos, no hacer filemtime() por archivo (muy lento)
    $skipFileMtimeCheck = $cutoff && $useNativeFiles && $totalFiles > 50000;
    if ($skipFileMtimeCheck) {
        $this->warn('Muchos archivos en red: se indexarán todos los PDFs con ruta válida (sin filtrar por fecha de modificación).');
    }

    $ok = 0; $skipped = 0; $bad = 0;

    foreach ($files as $file) {
        // Solo PDFs
        if (!preg_match('/\.pdf$/i', $file)) { $skipped++; continue; }

        // Filtro por "recientes" (solo omitir si tenemos mtime válido Y es antiguo)
        if ($cutoff && !$skipFileMtimeCheck) {
            try {
                $mtime = $useNativeFiles
                    ? @filemtime($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file))
                    : $disk->lastModified($file);
                // Si no se pudo leer la fecha (p. ej. en rutas UNC de red), incluir el archivo
                if ($mtime !== false && $mtime < $cutoff) {
                    $skipped++;
                    continue;
                }
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

        // --- Caso B (flexible): buscar "MES YYYY" y "DD MES" en cualquier posición de la ruta
        // Ej: HEMOTOLOGIA 2025/NOVIEMBRE 2025/26 NOVIEMBRE/archivo.pdf
        // Ej: HEMOTOLOGIA 2025/10. OCTUBRE 2025/29 OCTUBRE/archivo.pdf (prefijo "N. " opcional)
        if ($year === null && count($parts) >= 2) {
            foreach ($parts as $p) {
                $p = trim($p);
                // "NOVIEMBRE 2025" o "10. OCTUBRE 2025" -> año + mes (prefijo "N. " opcional)
                if (preg_match('/^\s*(\d+\.\s*)?([A-Za-zÁÉÍÓÚÜÑ]+)\s+(\d{4})\s*$/u', $p, $m1)) {
                    $monthName = mb_strtolower($m1[2], 'UTF-8');
                    if (isset($months[$monthName])) {
                        $year  = (int) $m1[3];
                        $month = (int) $months[$monthName];
                        break;
                    }
                }
            }
            foreach ($parts as $p) {
                $p = trim($p);
                // "26 NOVIEMBRE" o "31 MAYO 2024" -> día + mes (año opcional al final)
                if (preg_match('/^\s*(\d{1,2})\s+([A-Za-zÁÉÍÓÚÜÑ]+)(?:\s+\d{4})?\s*$/u', $p, $m2)) {
                    $monthName = mb_strtolower($m2[2], 'UTF-8');
                    if (isset($months[$monthName])) {
                        $day = (int) $m2[1];
                        if ($month === null) {
                            $month = (int) $months[$monthName];
                        }
                        // Si el segmento incluye año ("31 MAYO 2024") y aún no tenemos año, usarlo
                        if ($year === null && preg_match('/\s+(\d{4})\s*$/u', $p, $y)) {
                            $year = (int) $y[1];
                        }
                        break;
                    }
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
            ['name' => $filename, 'year' => $year, 'month' => $month, 'day' => $day, 'source' => PdfDocument::SOURCE_REMOTE]
        );
        $ok++;
    }

    $this->info("OK: {$ok} | Omitidos: {$skipped} | Con errores: {$bad}");
    return self::SUCCESS;
}

    /**
     * Meses en español para la estructura: HEMATOLOGIA AÑO / MES AÑO / DÍA MES
     */
    private const MONTHS_ES = [
        1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO',
        7 => 'JULIO', 8 => 'AGOSTO', 9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE',
    ];

    /**
     * Acceso directo a carpetas por fecha. Prioridad: estructura en español, fallback numérico.
     * Estructura real: ROOT / HEMATOLOGIA AÑO / MES AÑO / DÍA MES (ej: .../HEMATOLOGIA 2026/ENERO 2026/30 ENERO).
     *
     * @param string $root Ruta raíz del disco (ej: //labhematologia/LABHEMATOLOGIA)
     * @param array<int, \Carbon\Carbon> $dates Fechas a consultar (ej: hoy, ayer)
     * @return array<int, string> Rutas relativas de archivos
     */
    protected function scanTargetedDates(string $root, array $dates): array
    {
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
        if ($root === '' || !is_dir($root)) {
            return [];
        }
        $allFiles = [];
        $months = self::MONTHS_ES;

        foreach ($dates as $date) {
            $year = $date->year;
            $month = (int) $date->format('n');
            $day = (int) $date->format('j');
            $monthName = $months[$month] ?? null;
            if ($monthName === null) {
                continue;
            }

            // 1) Prioridad: estructura en español "HEMATOLOGIA 2026 / ENERO 2026 / 30 ENERO"
            $candidatesEs = [];
            $candidatesEs[] = "HEMATOLOGIA {$year}" . DIRECTORY_SEPARATOR . "{$monthName} {$year}" . DIRECTORY_SEPARATOR . "{$day} {$monthName}"; // 30 ENERO
            if ($day < 10) {
                $candidatesEs[] = "HEMATOLOGIA {$year}" . DIRECTORY_SEPARATOR . "{$monthName} {$year}" . DIRECTORY_SEPARATOR . sprintf('%02d', $day) . " {$monthName}"; // 05 ENERO
            }

            $addedForThisDate = false;
            foreach ($candidatesEs as $relDirEs) {
                $fullPathEs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDirEs);
                if (!is_dir($fullPathEs)) {
                    continue;
                }
                $relDirSlash = str_replace(DIRECTORY_SEPARATOR, '/', $relDirEs);
                $list = @scandir($fullPathEs);
                if ($list === false) {
                    continue;
                }
                foreach ($list as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $fullFile = $fullPathEs . DIRECTORY_SEPARATOR . $entry;
                    if (!is_file($fullFile)) {
                        continue;
                    }
                    $allFiles[] = $relDirSlash . '/' . $entry;
                }
                $addedForThisDate = true;
                break; // carpeta encontrada, no probar más variantes de día
            }

            if ($addedForThisDate) {
                continue; // ya añadimos archivos de esta fecha con estructura español
            }

            // 2) Fallback: ruta numérica estándar 2026/01/30 (y 2026/1/30)
            $y = $date->format('Y');
            $relSegmentsWithZero = [$y, $date->format('m'), $date->format('d')];
            $relSegmentsNoZero = [$y, (string) (int) $date->format('m'), (string) (int) $date->format('d')];
            foreach ([$relSegmentsWithZero, $relSegmentsNoZero] as $segments) {
                $relDir = implode('/', $segments);
                $fullPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
                if (!is_dir($fullPath)) {
                    continue;
                }
                $list = @scandir($fullPath);
                if ($list === false) {
                    continue;
                }
                foreach ($list as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $fullFile = $fullPath . DIRECTORY_SEPARATOR . $entry;
                    if (!is_file($fullFile)) {
                        continue;
                    }
                    $allFiles[] = $relDir . '/' . $entry;
                }
                break;
            }
        }

        return $allFiles;
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

    /**
     * Lista todos los archivos bajo $root usando PHP nativo (evita Flysystem en rutas UNC).
     * Si $windowDays está definido, solo entra en carpetas cuya ruta indica una fecha en la ventana (poda por nombre).
     */
    protected function allFilesNative(string $root, ?int $cutoff = null, array $windowDays = []): array
    {
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        try {
            if ($windowDays !== []) {
                $this->allFilesNativeRecurseByPath($root, '', $files, $windowDays);
            } elseif ($cutoff !== null) {
                $this->allFilesNativeRecurse($root, '', $cutoff, $files);
            } else {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $item) {
                    if ($item->isFile()) {
                        $full = $item->getPathname();
                        $rel = substr($full, strlen($root) + 1);
                        $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->error('Error listando carpeta: ' . $e->getMessage());
        }
        return $files;
    }

    /**
     * Recorre el árbol entrando solo en carpetas cuya ruta indica una fecha en $windowDays.
     * Ej: HEMATOLOGIA 2026 → ENERO 2026 → 29 ENERO (solo si (2026,1,29) está en la ventana).
     */
    protected function allFilesNativeRecurseByPath(string $root, string $relDir, array &$files, array $windowDays): void
    {
        $fullPath = $relDir === '' ? $root : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($fullPath)) {
            return;
        }
        $depth = $relDir === '' ? 0 : substr_count($relDir, '/') + 1;
        if ($depth <= 2) {
            $this->line('  → ' . ($relDir === '' ? '(raíz)' : $relDir));
        }
        $list = @scandir($fullPath);
        if ($list === false) {
            return;
        }
        foreach ($list as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $fullPath . DIRECTORY_SEPARATOR . $entry;
            $rel = $relDir === '' ? $entry : $relDir . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $entry);
            if (is_dir($full)) {
                $segments = $relDir === '' ? [$entry] : array_merge(explode('/', $relDir), [$entry]);
                $ymd = $this->parsePathSegmentsToYmd($segments);
                if (!$this->pathCouldBeInWindow($ymd, $windowDays)) {
                    continue; // esta rama no puede contener fechas de la ventana
                }
                $this->allFilesNativeRecurseByPath($root, $rel, $files, $windowDays);
            } else {
                $files[] = $rel;
            }
        }
    }

    /**
     * Extrae [año, mes, día] de los nombres de carpetas (MES YYYY, DD MES, YYYY, etc.).
     */
    protected function parsePathSegmentsToYmd(array $segments): array
    {
        $year = $month = $day = null;
        $months = [
            'enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,
            'julio'=>7,'agosto'=>8,'septiembre'=>9,'setiembre'=>9,'octubre'=>10,
            'noviembre'=>11,'diciembre'=>12,
        ];
        foreach ($segments as $p) {
            $p = trim($p);
            if (preg_match('/^\s*(\d+\.\s*)?([A-Za-zÁÉÍÓÚÜÑ]+)\s+(\d{4})\s*$/u', $p, $m)) {
                $monthName = mb_strtolower($m[2], 'UTF-8');
                $monthName = strtr($monthName, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
                if (isset($months[$monthName])) {
                    $year = (int) $m[3];
                    if ($month === null) {
                        $month = (int) $months[$monthName];
                    }
                }
            }
            if (preg_match('/^\s*(\d{1,2})\s+([A-Za-zÁÉÍÓÚÜÑ]+)(?:\s+\d{4})?\s*$/u', $p, $m)) {
                $monthName = mb_strtolower($m[2], 'UTF-8');
                $monthName = strtr($monthName, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
                if (isset($months[$monthName])) {
                    $day = (int) $m[1];
                    if ($month === null) {
                        $month = (int) $months[$monthName];
                    }
                    if ($year === null && preg_match('/\s+(\d{4})\s*$/u', $p, $y)) {
                        $year = (int) $y[1];
                    }
                }
            }
            if (preg_match('/\b(\d{4})\b/', $p, $m) && $year === null) {
                $year = (int) $m[1];
            }
        }
        return [$year, $month, $day];
    }

    /**
     * True si la ruta (con año/mes/día inferidos) podría contener archivos en la ventana de fechas.
     */
    protected function pathCouldBeInWindow(array $ymd, array $windowDays): bool
    {
        [$pathYear, $pathMonth, $pathDay] = $ymd;
        $windowYears = array_unique(array_column($windowDays, 0));
        $windowMonths = []; // (year, month) pairs
        foreach ($windowDays as $d) {
            $windowMonths[] = $d[0] . '-' . $d[1];
        }
        $windowMonths = array_unique($windowMonths);

        if ($pathYear !== null && !in_array($pathYear, $windowYears, true)) {
            return false;
        }
        if ($pathYear !== null && $pathMonth !== null) {
            $key = $pathYear . '-' . $pathMonth;
            if (!in_array($key, $windowMonths, true)) {
                return false;
            }
        }
        if ($pathYear !== null && $pathMonth !== null && $pathDay !== null) {
            $triple = [$pathYear, $pathMonth, $pathDay];
            $found = false;
            foreach ($windowDays as $d) {
                if ($d[0] === $triple[0] && $d[1] === $triple[1] && $d[2] === $triple[2]) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        return true;
    }

    /**
     * Recorre el árbol de carpetas pero salta las que tienen mtime < $cutoff (fallback cuando no hay ventana por ruta).
     */
    protected function allFilesNativeRecurse(string $root, string $relDir, int $cutoff, array &$files): void
    {
        $fullPath = $relDir === '' ? $root : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($fullPath)) {
            return;
        }
        $depth = $relDir === '' ? 0 : substr_count($relDir, '/') + 1;
        if ($depth <= 2) {
            $this->line('  → ' . ($relDir === '' ? '(raíz)' : $relDir));
        }
        $list = @scandir($fullPath);
        if ($list === false) {
            return;
        }
        foreach ($list as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $fullPath . DIRECTORY_SEPARATOR . $entry;
            $rel = $relDir === '' ? $entry : $relDir . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $entry);
            if (is_dir($full)) {
                $mtime = @filemtime($full);
                if ($mtime !== false && $mtime < $cutoff) {
                    continue;
                }
                $this->allFilesNativeRecurse($root, $rel, $cutoff, $files);
            } else {
                $files[] = $rel;
            }
        }
    }
}
