<?php

use App\Http\Controllers\BookController;
use App\Http\Controllers\RequestSyncController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/books');

Route::get('/books', [BookController::class, 'index'])->name('books.index');
Route::post('/books', [BookController::class, 'store'])->name('books.store');
Route::get('/books/{book}', [BookController::class, 'show'])->name('books.show');
Route::get('/books/{book}/file', [BookController::class, 'file'])->name('books.file');
Route::post('/books/{book}/process', [BookController::class, 'process'])->name('books.process');
Route::get('/books/{book}/status', [BookController::class, 'status'])->name('books.status');
Route::post('/books/{book}/publish', [BookController::class, 'publish'])->name('books.publish');
Route::delete('/books/{book}', [BookController::class, 'destroy'])->name('books.destroy');

Route::get('/permintaan', [RequestSyncController::class, 'index'])->name('sync.requests.index');
Route::post('/permintaan/import', [RequestSyncController::class, 'import'])->name('sync.requests.import');
