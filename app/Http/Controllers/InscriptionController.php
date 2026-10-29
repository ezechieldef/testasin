<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInscriptionRequest;
use App\Http\Resources\InscriptionResource;
use App\Services\RegisterInscription;
use Illuminate\Http\JsonResponse;

class InscriptionController extends Controller
{
    public function store(StoreInscriptionRequest $request, RegisterInscription $registration): JsonResponse
    {
        $data = $request->validated();
        $inscription = $registration->register(
            $data['npi'], $data['date_naissance'], $data['concours'], $data['centre'],
        );

        return (new InscriptionResource($inscription))->response()->setStatusCode(201);
    }
}
