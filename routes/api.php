<?php

use App\Http\Controllers\Api\DatasetController;
use App\Http\Controllers\Api\RowController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.key')->group(function () {
    Route::get('datasets', [DatasetController::class, 'index']);
    Route::post('datasets', [DatasetController::class, 'store']);
    Route::get('datasets/{dataset}', [DatasetController::class, 'show']);
    Route::delete('datasets/{dataset}', [DatasetController::class, 'destroy']);

    Route::get('datasets/{dataset}/rows', [RowController::class, 'index']);
    Route::get('datasets/{dataset}/summary', [RowController::class, 'summary']);
});
