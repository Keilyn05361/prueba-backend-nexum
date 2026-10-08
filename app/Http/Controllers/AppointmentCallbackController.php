<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAppointmentRequest;
use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AppointmentCallbackController extends Controller
{
    public function __invoke(StoreAppointmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        // createOrFirst intenta insertar; si el índice único de external_id lo rechaza,
        // devuelve el registro existente. Es seguro incluso con peticiones simultáneas.
        $appointment = Appointment::createOrFirst(
            ['external_id' => $data['external_id']],
            [
                'rfc' => $data['rfc'],
                'scheduled_at' => Carbon::parse($data['scheduled_at'])->utc(),
            ],
        );

        if (! $appointment->wasRecentlyCreated) {
            return response()->json($appointment, 200);
        }

        SendAppointmentNotification::dispatch($appointment);

        return response()->json($appointment, 201);
    }
}
