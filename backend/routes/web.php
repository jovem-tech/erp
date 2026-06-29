<?php

use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/webhooks/whatsapp', WhatsAppWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.whatsapp');
