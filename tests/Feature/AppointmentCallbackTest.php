<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AppointmentCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    private array $payload = [
        'external_id' => 'APT-1001',
        'rfc' => 'GODE561231GR8',
        'scheduled_at' => '2026-10-15T10:30:00Z',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.appointments.webhook_secret' => self::SECRET]);
        Queue::fake();
    }

    public function test_valid_signature_creates_appointment_and_dispatches_job(): void
    {
        $response = $this->sendWebhook($this->payload);

        $response->assertCreated();
        $this->assertDatabaseHas('appointments', [
            'external_id' => 'APT-1001',
            'rfc' => 'GODE561231GR8',
        ]);
        Queue::assertPushed(SendAppointmentNotification::class, 1);
    }

    public function test_invalid_signature_returns_401_and_saves_nothing(): void
    {
        $response = $this->sendWebhook($this->payload, signature: 'invalid-signature');

        $response->assertUnauthorized();
        $this->assertDatabaseCount('appointments', 0);
        Queue::assertNothingPushed();
    }

    public function test_same_external_id_is_stored_only_once(): void
    {
        $this->sendWebhook($this->payload)->assertCreated();
        $this->sendWebhook($this->payload)->assertOk();

        $this->assertSame(1, Appointment::where('external_id', 'APT-1001')->count());
        Queue::assertPushed(SendAppointmentNotification::class, 1);
    }

    public function test_invalid_data_returns_422(): void
    {
        $response = $this->sendWebhook(['external_id' => 'APT-1002', 'rfc' => 'NOT-AN-RFC']);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['rfc', 'scheduled_at']);
        $this->assertDatabaseCount('appointments', 0);
    }

    /**
     * Envía el JSON crudo firmado con HMAC-SHA256, igual que lo haría el emisor real.
     */
    private function sendWebhook(array $payload, ?string $signature = null): TestResponse
    {
        $body = json_encode($payload);
        $signature ??= hash_hmac('sha256', $body, self::SECRET);

        return $this->call('POST', '/api/appointments/callback', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature,
        ], content: $body);
    }
}
