<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\GuestUploadController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\PhotoSelectionController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});
Route::middleware('auth')->group(function () {
    Route::get('/', [CollectionController::class, 'index'])->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/collections', [CollectionController::class, 'store'])->middleware('throttle:collection-create')->name('collections.store');
    Route::get('/collections/{collection}', [CollectionController::class, 'show'])->name('collections.show');
    Route::post('/collections/{collection}/photos/selection', [PhotoSelectionController::class, 'store'])->middleware('throttle:photo-selection')->name('photos.selection');
    Route::post('/collections/{collection}/close', [CollectionController::class, 'close'])->name('collections.close');
    Route::get('/photos/{photo}', [PhotoController::class, 'show'])->name('photos.show');
    Route::get('/photos/{photo}/thumbnail', [PhotoController::class, 'thumbnail'])->name('photos.thumbnail');
    Route::delete('/photos/{photo}', [PhotoController::class, 'destroy'])->name('photos.destroy');
});
Route::get('/u/{token}', [GuestUploadController::class, 'show'])->where('token', '[a-f0-9]{64}')->middleware('throttle:guest-view')->name('upload');
Route::post('/u/{token}', [GuestUploadController::class, 'store'])->where('token', '[a-f0-9]{64}')->middleware('throttle:guest-upload')->name('upload.store');
