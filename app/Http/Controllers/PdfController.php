<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\PdfDocument;
use App\Http\Resources\PdfResource;

class PdfController extends Controller
{
    /**
     * Devuelve un listado paginado de PDFs filtrados por año/mes/día y búsqueda por nombre.
     */
    public function index(Request $request)
    {
        // 1) Validación de inputs
        $data = $request->validate([
            'year'   => ['nullable', 'integer', 'between:1900,2100'],
            'month'  => ['nullable', 'integer', 'between:1,12'],
            'day'    => ['nullable', 'integer', 'between:1,31'],
            'search' => ['nullable', 'string', 'max:100'],
            'page'   => ['nullable', 'integer', 'min:1'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $year   = $data['year']   ?? null;
        $month  = $data['month']  ?? null;
        $day    = $data['day']    ?? null;
        $search = $data['search'] ?? null;
        $page   = $data['page']   ?? 1;
        $limit  = $data['limit']  ?? 20;

        // 2) Cache key completo (incluye limit y page)
        $cacheKey = sprintf(
            'pdfs:y:%s:m:%s:d:%s:q:%s:page:%d:limit:%d',
            $year ?? 'any',
            $month ?? 'any',
            $day ?? 'any',
            $search ? sha1($search) : 'none',
            $page,
            $limit
        );

        // 3) Cache con 5 min
        $paginator = Cache::remember($cacheKey, 300, function () use ($year, $month, $day, $search, $limit, $page) {
            $q = PdfDocument::query()
                ->when($year,  fn($qq) => $qq->where('year',  $year))
                ->when($month, fn($qq) => $qq->where('month', $month))
                ->when($day,   fn($qq) => $qq->where('day',   $day))
                ->when($search, function ($qq) use ($search) {
                    $qq->where('name', 'like', '%' . str_replace('%', '\%', $search) . '%');
                });

            $paginator = $q->orderByDesc('year')
                           ->orderByDesc('month')
                           ->orderByDesc('day')
                           ->paginate($limit, ['*'], 'page', $page);

            // Conserva filtros en los links de paginación
            $paginator->appends([
                'year'   => $year,
                'month'  => $month,
                'day'    => $day,
                'search' => $search,
                'limit'  => $limit,
            ]);

            return $paginator;
        });

        // 4) Respuesta consistente con Resource (incluye meta/links del paginator)
        return PdfResource::collection($paginator);
    }
}
