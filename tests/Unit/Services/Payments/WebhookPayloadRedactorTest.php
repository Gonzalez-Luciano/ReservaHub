<?php

namespace Tests\Unit\Services\Payments;

use App\Services\Payments\WebhookPayloadRedactor;
use Tests\TestCase;

class WebhookPayloadRedactorTest extends TestCase
{
    public function test_it_keeps_only_whitelisted_keys(): void
    {
        $redacted = (new WebhookPayloadRedactor)->redact([
            'event_id' => 'evt_1',
            'payment_id' => 'sim_pay_1',
            'status' => 'approved',
            'amount' => '10.00',
            'currency' => 'ARS',
            'occurred_at' => '2026-08-18T10:00:00+00:00',
            'reference' => '01J000000000000000000000',
            'failure_reason' => null,
            'card_number' => '4111111111111111',
            'cvv' => '123',
            'customer_email' => 'cliente@example.com',
            'authorization' => 'Bearer secret',
        ]);

        $this->assertSame([
            'event_id' => 'evt_1',
            'payment_id' => 'sim_pay_1',
            'status' => 'approved',
            'amount' => '10.00',
            'currency' => 'ARS',
            'occurred_at' => '2026-08-18T10:00:00+00:00',
            'reference' => '01J000000000000000000000',
            'failure_reason' => null,
        ], $redacted);
    }

    public function test_it_drops_nested_structures_that_are_not_whitelisted(): void
    {
        $redacted = (new WebhookPayloadRedactor)->redact([
            'status' => 'rejected',
            'payer' => ['email' => 'cliente@example.com', 'document' => '20123456789'],
            'raw' => ['signature' => 'v1=deadbeef'],
        ]);

        $this->assertSame(['status' => 'rejected'], $redacted);
    }

    public function test_it_returns_an_empty_array_when_nothing_is_whitelisted(): void
    {
        $this->assertSame([], (new WebhookPayloadRedactor)->redact(['token' => 'abc', 'secret' => 'xyz']));
    }
}
