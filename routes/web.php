<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebrtcStreamingController;

Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');
