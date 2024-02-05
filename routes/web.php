<?php

use Illuminate\Support\Facades\Route;

use \App\Http\Controllers\MainController;
use \App\Http\Controllers\FormController;

Route::get('/', [FormController::class, 'index'])->name('home');

Route::get('/api/credentials', [MainController::class, 'credentials'])->name('api.credentials');

Route::post('/api/lead_create', [MainController::class, 'leadRequest'])->name('api.lead_create');
