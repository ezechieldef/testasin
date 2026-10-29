<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InscriptionResource extends JsonResource
{
    /** @return array{id: int, numero_table: string, statut: string} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'numero_table' => $this->resource['numero_table'],
            'statut' => $this->resource['statut'],
        ];
    }
}
