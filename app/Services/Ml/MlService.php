<?php

namespace App\Services\Ml;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Client for the ML API (ml-service/, FastAPI on 127.0.0.1:8001, config('services.ml')).
 *
 * Every call returns an MlResult and never throws: if the API is not running, not configured,
 * slow (5 s timeout) or answers with an error, the result says so and the problem is logged,
 * so an admin page can show "offline" instead of crashing. The API key is sent as X-API-Key and never logged.
 */
class MlService
{
    public function configured(): bool
    {
        return filled(config('services.ml.url')) && filled(config('services.ml.key'));
    }

    /** Models, training dates, key metrics, cache and retrain status. */
    public function health(): MlResult
    {
        return $this->send('get', '/health');
    }

    /** Daily revenue forecast from tomorrow (1-30 days) plus the last 30 days of actual revenue. */
    public function forecast(int $days = 30): MlResult
    {
        return $this->send('get', '/forecast/revenue', ['days' => max(1, min(30, $days))]);
    }

    /**
     * Restock report (all groups, brand roll-up, summary).
     *
     * @param  list<string>  $risk
     */
    public function restock(?string $asOf = null, array $risk = []): MlResult
    {
        return $this->send('get', '/restock/report', array_filter(['as_of' => $asOf, 'risk' => implode(',', $risk)]));
    }

    /**
     * Suggested price for one pair.
     *
     * @param  array<string, mixed>  $product  brand, name, category, condition, sole_condition, box_included, size, color, material, retail_price
     */
    public function recommendPrice(array $product): MlResult
    {
        return $this->send('post', '/price/recommend', $product);
    }

    /** Start retraining all models in the background (the API answers 202 straight away). */
    public function retrain(): MlResult
    {
        return $this->send('post', '/admin/retrain', [], (int) config('services.ml.retrain_timeout', 30));
    }

    /**
     * @param  array<string, mixed>  $payload  query string for GET, JSON body for POST
     */
    private function send(string $method, string $path, array $payload = [], ?int $timeout = null): MlResult
    {
        if (! $this->configured()) {
            return MlResult::failure('ML insights are not configured (set ML_API_URL and ML_API_KEY in .env).');
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.ml.url'), '/'))
                ->withHeaders(['X-API-Key' => (string) config('services.ml.key')])
                ->acceptJson()
                ->connectTimeout((int) config('services.ml.connect_timeout', 2))
                ->timeout($timeout ?? (int) config('services.ml.timeout', 5))
                ->{$method}($path, $payload);
        } catch (ConnectionException $e) {
            Log::warning('ML API offline', ['path' => $path, 'message' => $e->getMessage()]);

            return MlResult::failure('The ML service is offline (it did not answer). Start it with ml-service\\run_api.bat.');
        } catch (Throwable $e) {
            Log::warning('ML API call failed', ['path' => $path, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return MlResult::failure('The ML service could not be reached.');
        }

        $json = $response->json();
        if ($response->successful() && is_array($json)) {
            return MlResult::success($json, $response->status());
        }

        $status = $response->status();
        $error = is_array($json) && isset($json['error']) ? (string) $json['error'] : "The ML service answered with an error (HTTP {$status}).";
        $details = is_array($json) && is_array($json['details'] ?? null) ? array_values(array_map('strval', $json['details'])) : [];
        if ($status === 401 || $status >= 500) {
            Log::warning('ML API error', ['path' => $path, 'status' => $status, 'error' => $error]);
        }

        return MlResult::failure($error, $status, $details);
    }
}
