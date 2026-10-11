<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends one text message. Driver comes from config('services.sms.driver'):
 *
 *   log        (default) writes to the Laravel log only — nothing is sent.
 *   semaphore  sends through https://semaphore.co (needs SEMAPHORE_API_KEY).
 *
 * The message may contain a one-time setup link, so it is never written to
 * the log unless APP_DEBUG=true, and failures log the exception class only.
 *
 * Returns SENT, LOGGED (log driver: nothing really sent) or FAILED.
 */
class SmsSender
{
    public const SENT = 'sent';
    public const LOGGED = 'logged';
    public const FAILED = 'failed';

    public function send(string $to, string $message): string
    {
        $driver = (string) config('services.sms.driver', 'log');

        try {
            if ($driver === 'semaphore') {
                $key = config('services.sms.semaphore.key');
                if (! $key) {
                    Log::warning('SMS not sent: SEMAPHORE_API_KEY is not set.');

                    return self::FAILED;
                }

                $payload = [
                    'apikey' => $key,
                    // Semaphore takes 09XXXXXXXXX or 639XXXXXXXXX.
                    'number' => ltrim($to, '+'),
                    'message' => $message,
                ];
                if ($sender = config('services.sms.semaphore.sender')) {
                    $payload['sendername'] = $sender;
                }

                $response = Http::asForm()->timeout(10)->post('https://api.semaphore.co/api/v4/messages', $payload);

                if (! $response->successful()) {
                    Log::warning('SMS rejected by Semaphore (HTTP ' . $response->status() . ').');

                    return self::FAILED;
                }

                return self::SENT;
            }

            Log::info('SMS (log driver, not sent) to ' . self::mask($to) . (config('app.debug') ? ': ' . $message : ''));

            return self::LOGGED;
        } catch (\Throwable $e) {
            Log::warning('SMS could not be sent (' . $e::class . ').');

            return self::FAILED;
        }
    }

    private static function mask(string $number): string
    {
        return strlen($number) > 6 ? substr($number, 0, 4) . str_repeat('*', strlen($number) - 6) . substr($number, -2) : '***';
    }
}
