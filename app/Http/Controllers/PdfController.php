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
        $year   = $request->query('year');
        $month  = $request->query('month');
        $day    = $request->query('day');
        $search = $request->query('search');
        $page   = $request->query('page', 1);
        $limit  = $request->query('limit', 20);

        $cacheKey = "pdfs:{$year}:{$month}:{$day}:{$search}:page{$page}";

        $paginator = Cache::remember($cacheKey, 300, function () use ($year, $month, $day, $search, $limit) {
            $q = PdfDocument::query();

            if ($year)  $q->where('year', $year);
            if ($month) $q->where('month', $month);
            if ($day)   $q->where('day', $day);
            if ($search) {
                $q->where('name', 'like', "%{$search}%");
            }

            return $q->orderByDesc('year')
                     ->orderByDesc('month')
                     ->orderByDesc('day')
                     ->paginate($limit);
        });

        // Envuelve cada item con PdfResource para homogeneizar la respuesta
        return PdfResource::collection($paginator);
    }
}
