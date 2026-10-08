<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.appointments.webhook_secret');
        $signature = (string) $request->header('X-Signature');

        // La firma se calcula sobre el body crudo, tal como lo envió el emisor
        $expected = hash_hmac('sha256', $request->getContent(), (string) $secret);

        // hash_equals compara en tiempo constante (evita timing attacks)
        if (empty($secret) || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        return $next($request);
    }
}
