<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'workflow_id' => "okeeee",
        'result' => "masukkk"
    ]);
});
