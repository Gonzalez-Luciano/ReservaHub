<?php

namespace App\Services\Payments\Data;

/**
 * Entrada provider-neutral del borde de webhook. La construyen por igual el
 * controller HTTP y el job de entrega simulada; ningún tipo de Laravel entra al
 * contrato del gateway.
 */
final readonly class WebhookEnvelope
{
    /** @var array<string, string> */
    public array $headers;

    /**
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function __construct(public string $rawBody, array $headers)
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }

        $this->headers = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
