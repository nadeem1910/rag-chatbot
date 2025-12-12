<?php

use App\Http\Controllers\UploadController;
use App\Http\Controllers\ChatController;

// Upload page
Route::get('/', function () {
    return view('upload');
})->name('home');

Route::post('/upload', [UploadController::class, 'store'])->name('upload.store');

// Chat routes
Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
Route::post('/chat', [ChatController::class, 'ask'])->name('chat.ask');