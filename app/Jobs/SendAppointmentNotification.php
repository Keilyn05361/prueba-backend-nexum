<?php

namespace App\Jobs;

use App\Models\Appointment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendAppointmentNotification implements ShouldQueue
{
    use Queueable;

    // Número máximo de intentos antes de marcarlo como fallido (tabla failed_jobs)
    public int $tries = 3;

    // Segundos de espera entre reintentos: 10s antes del 2.º intento, 30s antes del 3.º
    public array $backoff = [10, 30];

    public function __construct(public Appointment $appointment)
    {
    }

    public function handle(): void
    {
        Log::info('Appointment notification sent (simulated).', [
            'appointment_id' => $this->appointment->id,
            'external_id' => $this->appointment->external_id,
            'rfc' => $this->appointment->rfc,
            'scheduled_at' => $this->appointment->scheduled_at->toIso8601String(),
            'attempt' => $this->attempts(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Appointment notification failed after all retries.', [
            'external_id' => $this->appointment->external_id,
            'error' => $exception->getMessage(),
        ]);
    }
}
