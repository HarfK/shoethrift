<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prints the webhook URL to register in the HitPay dashboard (Developers → Webhook Endpoints),
 * built from HITPAY_PUBLIC_URL (e.g. your ngrok address). HitPay only calls public HTTPS URLs.
 */
class HitPayWebhookUrl extends Command
{
    protected $signature = 'hitpay:webhook-url';

    protected $description = 'Show the HitPay webhook URL to register in the dashboard (from HITPAY_PUBLIC_URL)';

    public function handle(): int
    {
        $base = rtrim((string) config('services.hitpay.public_url'), '/');

        if ($base === '') {
            $this->error('HITPAY_PUBLIC_URL is not set. Set it to your public HTTPS address (e.g. your ngrok URL) in .env, then run php artisan config:clear.');

            return self::FAILURE;
        }

        if (! str_starts_with($base, 'https://')) {
            $this->warn('HITPAY_PUBLIC_URL should start with https://. HitPay only sends webhooks to public HTTPS URLs.');
        }

        $this->line('Register this URL in the HitPay dashboard → Developers → Webhook Endpoints:');
        $this->newLine();
        $this->info('  '.$base.route('webhooks.hitpay', absolute: false));
        $this->newLine();
        $this->line('Event: payment_request.completed. Then put that endpoint\'s salt in HITPAY_SALT.');
        $this->line('On ngrok\'s free plan the address changes on every restart, so update both when it does.');

        return self::SUCCESS;
    }
}
