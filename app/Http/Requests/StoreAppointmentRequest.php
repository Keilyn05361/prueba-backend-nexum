<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización ya la hizo el middleware de firma HMAC
        return true;
    }

    public function rules(): array
    {
        return [
            'external_id' => ['required', 'string', 'max:255'],
            // RFC mexicano: 3 letras (moral) o 4 (física) + fecha AAMMDD + homoclave de 3
            'rfc' => ['required', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u'],
            'scheduled_at' => ['required', 'date'],
        ];
    }
}
