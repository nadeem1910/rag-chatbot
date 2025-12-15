<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

// ========================================
// ✅ ADD THIS CODE - Auto-create database
// ========================================
if (app()->environment('production')) {
    $dbPath = database_path('database.sqlite');
    
    // Create database directory if not exists
    if (!file_exists(dirname($dbPath))) {
        mkdir(dirname($dbPath), 0755, true);
    }
    
    // Create database file if not exists
    if (!file_exists($dbPath)) {
        touch($dbPath);
        chmod($dbPath, 0644);
    }
}

return $app;