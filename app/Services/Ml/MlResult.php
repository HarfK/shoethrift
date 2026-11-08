<?php

namespace App\Services\Ml;

/**
 * The outcome of one call to the ML API. Pages check ok(); a failed result carries a short,
 * admin-readable error (and the API's details, e.g. validation messages) instead of an exception.
 */
final class MlResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $details
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $data = [],
        public readonly ?int $status = null,
        public readonly ?string $error = null,
        public readonly array $details = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(array $data, int $status = 200): self
    {
        return new self(true, $data, $status);
    }

    /**
     * @param  list<string>  $details
     */
    public static function failure(string $error, ?int $status = null, array $details = []): self
    {
        return new self(false, [], $status, $error, $details);
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    /**
     * The API couldn't be used at all (not running, not configured, wrong key, server error),
     * as opposed to rejecting the input (422) or a conflict (409).
     */
    public function offline(): bool
    {
        return ! $this->ok && ($this->status === null || $this->status === 401 || $this->status >= 500);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }
}
