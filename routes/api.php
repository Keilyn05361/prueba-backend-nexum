<?php

use App\Http\Controllers\AppointmentCallbackController;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::post('/appointments/callback', AppointmentCallbackController::class)
    ->middleware(VerifyWebhookSignature::class);
