<?php

use App\Http\Controllers\InscriptionController;
use Illuminate\Support\Facades\Route;

Route::post('/inscriptions', [InscriptionController::class, 'store'])->name('inscriptions.store');
