<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Jobs\DeliverSimulatedProviderWebhook;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\CheckoutRequest;
use App\Services\Payments\Simulated\SimulatedProviderPayment;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class SimulatedCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function checkout(?DateTimeImmutable $expiresAt = null): string
    {
        return app(PaymentGateway::class)->createCheckout(new CheckoutRequest(
            reference: (string) Str::ulid(),
            amount: '10.00',
            currency: 'ARS',
            description: 'Seña',
            returnUrl: 'http://localhost/mis-reservas',
            expiresAt: $expiresAt ?? new DateTimeImmutable('+30 minutes'),
        ))->externalId;
    }

    private function outcomeUrl(string $externalId): string
    {
        return URL::temporarySignedRoute(
            'demo.payments.outcome',
            new DateTimeImmutable('+30 minutes'),
            ['externalId' => $externalId],
        );
    }

    public function test_the_checkout_page_needs_a_valid_signature(): void
    {
        $externalId = $this->checkout();
        $signed = app(PaymentGateway::class)->checkoutUrl($externalId, new DateTimeImmutable('+30 minutes'));

        $this->get($signed)->assertOk();
        $this->get("/demo/pagos/{$externalId}/checkout")->assertForbidden();
    }

    public function test_the_page_shows_the_demo_banner_and_no_card_fields(): void
    {
        $externalId = $this->checkout();
        $signed = app(PaymentGateway::class)->checkoutUrl($externalId, new DateTimeImmutable('+30 minutes'));

        $response = $this->get($signed)->assertOk();
        $content = $response->getContent();

        // El árbol de React (incluido el banner) nunca se renderiza en el
        // servidor en este proyecto (sin SSR): la respuesta HTTP inicial es
        // solo el shell `data-page`. Por eso el banner se verifica leyendo el
        // componente fuente directamente, no la respuesta HTTP.
        $source = file_get_contents(resource_path('js/Pages/Demo/Checkout.jsx'));
        $this->assertStringContainsString('ENTORNO DE DEMOSTRACIÓN', $source);

        // Estos sí son observables en la respuesta HTTP: verifican que el
        // servidor nunca filtra identificadores de tarjeta en el payload
        // `data-page`.
        $this->assertStringNotContainsStringIgnoringCase('card_number', $content);
        $this->assertStringNotContainsStringIgnoringCase('cvv', $content);
    }

    public function test_the_checkout_page_hands_out_its_own_signed_outcome_url(): void
    {
        $externalId = $this->checkout();
        $signed = app(PaymentGateway::class)->checkoutUrl($externalId, new DateTimeImmutable('+30 minutes'));

        $props = $this->get($signed)->assertOk()->viewData('page')['props'];

        $this->assertArrayHasKey('outcome_url', $props);
        $this->assertStringContainsString("/demo/pagos/{$externalId}/resultado", $props['outcome_url']);
        $this->assertStringContainsString('signature=', $props['outcome_url']);
    }

    public function test_the_outcome_route_rejects_an_unsigned_post(): void
    {
        $externalId = $this->checkout();

        $this->post("/demo/pagos/{$externalId}/resultado", ['outcome' => 'approved'])->assertForbidden();
    }

    public function test_approving_mutates_the_provider_and_queues_the_delivery(): void
    {
        Queue::fake();
        $externalId = $this->checkout();

        $this->post($this->outcomeUrl($externalId), ['outcome' => 'approved'])->assertRedirect();

        $this->assertSame(
            PaymentStatus::Approved,
            SimulatedProviderPayment::where('external_id', $externalId)->firstOrFail()->status,
        );
        Queue::assertPushed(DeliverSimulatedProviderWebhook::class, fn ($job) => $job->externalId === $externalId
            && $job->status === PaymentStatus::Approved);
    }

    public function test_rejecting_mutates_the_provider_and_queues_the_delivery(): void
    {
        Queue::fake();
        $externalId = $this->checkout();

        $this->post($this->outcomeUrl($externalId), ['outcome' => 'rejected'])->assertRedirect();

        $this->assertSame(
            PaymentStatus::Rejected,
            SimulatedProviderPayment::where('external_id', $externalId)->firstOrFail()->status,
        );
        Queue::assertPushed(DeliverSimulatedProviderWebhook::class, fn ($job) => $job->status === PaymentStatus::Rejected);
    }

    public function test_abandoning_changes_nothing_and_queues_nothing(): void
    {
        Queue::fake();
        $externalId = $this->checkout();

        $this->post($this->outcomeUrl($externalId), ['outcome' => 'abandoned'])->assertRedirect();

        $this->assertSame(
            PaymentStatus::Pending,
            SimulatedProviderPayment::where('external_id', $externalId)->firstOrFail()->status,
        );
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_outcome_is_rejected(): void
    {
        Queue::fake();
        $externalId = $this->checkout();

        $this->post($this->outcomeUrl($externalId), ['outcome' => 'refunded'])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_an_expired_provider_payment_cannot_be_approved(): void
    {
        Queue::fake();
        $externalId = $this->checkout(new DateTimeImmutable('-1 minute'));

        $this->post($this->outcomeUrl($externalId), ['outcome' => 'approved'])->assertRedirect();

        $this->assertSame(
            PaymentStatus::Expired,
            SimulatedProviderPayment::where('external_id', $externalId)->firstOrFail()->status,
        );
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_external_id_is_a_404(): void
    {
        $this->post($this->outcomeUrl('sim_pay_nope'), ['outcome' => 'approved'])->assertNotFound();
    }
}
