<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PdfResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'name'  => $this->name,
            'url'   => $this->url,
            'year'  => $this->year,
            'month' => $this->month,
            'day'   => $this->day,
        ];
    }
}
