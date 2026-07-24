<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Yoco\Yoco;

Route::post('/extensions/yoco/webhook', [Yoco::class, 'webhook'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('extensions.gateways.yoco.webhook');

Route::get('/extensions/yoco/return/{invoice}', [Yoco::class, 'returnFromYoco'])
    ->middleware(['web'])
    ->name('extensions.gateways.yoco.return');

Route::get('/extensions/yoco/cancel/{invoice}', [Yoco::class, 'cancelFromYoco'])
    ->middleware(['web'])
    ->name('extensions.gateways.yoco.cancel');