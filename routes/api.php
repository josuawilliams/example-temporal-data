<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TemporalController;



Route::post('/temporal/example', [TemporalController::class, 'runExample']);
Route::get('/temporal/example/{workflowId}', [TemporalController::class, 'getResult']);
