<?php

use App\Http\Controllers\UploadController;
use App\Http\Controllers\ChatController;


Route::get('/', function () {
    return view('upload'); // simple upload page
});

Route::post('/upload', [UploadController::class, 'store'])->name('upload.store');

Route::get('/chat', function () {
    return view('chat');
});

Route::post('/chat', [ChatController::class, 'ask'])->name('chat.ask');

