<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TemporalController;



Route::get('/temporal/report', [TemporalController::class, 'report']);
Route::post('/temporal/report/refresh', [TemporalController::class, 'refreshReportViews']);
Route::post('/temporal/example', [TemporalController::class, 'runExample']);
Route::get('/temporal/example/{workflowId}', [TemporalController::class, 'getResult']);
