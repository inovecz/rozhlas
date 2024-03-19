<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebrtcStreamingController;

//Route::get('/streaming', [WebrtcStreamingController::class, 'index']);
//Route::get('/streaming/{streamId}', [WebrtcStreamingController::class, 'consumer']);
Route::post('/stream-offer', [WebrtcStreamingController::class, 'makeStreamOffer']);
Route::post('/stream-answer', [WebrtcStreamingController::class, 'makeStreamAnswer']);

Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');
