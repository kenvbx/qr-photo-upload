<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\GuestUploadController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\PhotoExportController;
use App\Http\Controllers\PhotoLifecycleController;
use App\Http\Controllers\PhotoSelectionController;
use App\Http\Controllers\ResumableUploadController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});
Route::middleware('auth')->group(function () {
    Route::post('/collections/{collection}/event', [EventController::class, 'update'])->name('event.update');
    Route::get('/collections/{collection}/logo', [EventController::class, 'logo'])->name('event.logo');
    Route::get('/collections/{collection}/trash', [PhotoLifecycleController::class, 'index'])->name('trash.index');
    Route::post('/trash/{id}/restore', [PhotoLifecycleController::class, 'restore'])->middleware('throttle:photo-selection')->name('trash.restore');
    Route::delete('/trash/{id}', [PhotoLifecycleController::class, 'purge'])->middleware('throttle:photo-selection')->name('trash.purge');
    Route::post('/collections/{collection}/review', [PhotoLifecycleController::class, 'moderate'])->middleware('throttle:photo-selection')->name('photos.moderate');
    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('/photos/{photo}/retry', [TaskController::class, 'retry'])->middleware('throttle:photo-selection')->name('photos.retry');
    Route::post('/collections/{collection}/settings', [CollectionController::class, 'update'])->name('collections.update');
    Route::get('/', [CollectionController::class, 'index'])->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/collections', [CollectionController::class, 'store'])->middleware('throttle:collection-create')->name('collections.store');
    Route::get('/collections/{collection}', [CollectionController::class, 'show'])->name('collections.show');
    Route::post('/collections/{collection}/photos/selection', [PhotoSelectionController::class, 'store'])->middleware('throttle:photo-selection')->name('photos.selection');
    Route::post('/collections/{collection}/close', [CollectionController::class, 'close'])->name('collections.close');
    Route::get('/exports/{export}', [PhotoExportController::class, 'show'])->name('exports.show');
    Route::get('/exports/{export}/download', [PhotoExportController::class, 'download'])->name('exports.download');
    Route::post('/exports/{export}/retry', [PhotoExportController::class, 'retry'])->middleware('throttle:photo-selection')->name('exports.retry');
    Route::get('/photos/{photo}', [PhotoController::class, 'show'])->name('photos.show');
    Route::get('/photos/{photo}/thumbnail', [PhotoController::class, 'thumbnail'])->name('photos.thumbnail');
    Route::delete('/photos/{photo}', [PhotoController::class, 'destroy'])->name('photos.destroy');
});
Route::get('/u/{token}', [GuestUploadController::class, 'show'])->where('token', '[a-f0-9]{64}')->middleware('throttle:guest-view')->name('upload');
Route::post('/u/{token}', [GuestUploadController::class, 'store'])->where('token', '[a-f0-9]{64}')->middleware('throttle:guest-upload')->name('upload.store');

Route::post('/u/{token}/unlock', [GuestUploadController::class, 'unlock'])->where('token', '[a-f0-9]{64}')->middleware('throttle:5,1')->name('upload.unlock');

Route::get('/u/{token}/logo', [EventController::class, 'guestLogo'])->where('token', '[a-f0-9]{64}')->middleware('throttle:guest-view')->name('upload.logo');
Route::prefix('/u/{token}/resumable')->where(['token' => '[a-f0-9]{64}'])->middleware('throttle:guest-chunks')->group(function () {
    Route::post('/', [ResumableUploadController::class, 'store']);
    Route::post('/{id}/chunks', [ResumableUploadController::class, 'chunk'])->whereUuid('id');
    Route::post('/{id}/finish', [ResumableUploadController::class, 'finish'])->whereUuid('id');
    Route::delete('/{id}', [ResumableUploadController::class, 'destroy'])->whereUuid('id');
});
Route::prefix('/a/{token}')->where(['token' => '[a-f0-9]{64}'])->middleware('throttle:album-view')->group(function () {
    Route::get('/', [AlbumController::class, 'show'])->name('album.show');
    Route::get('/photos/{id}', [AlbumController::class, 'photo'])->whereNumber('id')->name('album.photo');
    Route::get('/logo', [AlbumController::class, 'logo'])->name('album.logo');
});
