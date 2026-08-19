<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\InitiatePayment;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Jobs\DeliverSimulatedProviderWebhook;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Simulated\SimulatedProviderPayment;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SimulatedCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crea una reserva pendiente con seña y la inicia de verdad a través de
     * `InitiatePayment`, así el `Payment` local (que el controller ahora
     * exige) y el estado del proveedor nacen juntos, exactamente como en
     * producción.
     *
     * @param  array<string, mixed>  $bookingOverrides
     * @return array{0: Booking, 1: Payment}
     */
    private function scenario(array $bookingOverrides = []): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC', 'currency' => 'ARS']);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['deposit_amount' => '10.00']);

        $booking = Booking::factory()->create(array_merge([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Pending,
            'deposit_amount' => '10.00',
            'payment_expires_at' => now()->addMinutes(30),
        ], $bookingOverrides));

        $payment = app(InitiatePayment::class)->handle($booking, $customer);

        return [$booking, $payment];
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
        [, $payment] = $this->scenario();
        $signed = app(PaymentGateway::class)->checkoutUrl($payment->external_id, new DateTimeImmutable('+30 minutes'));

        $this->get($signed)->assertOk();
        $this->get("/demo/pagos/{$payment->external_id}/checkout")->assertForbidden();
    }

    public function test_the_page_shows_the_demo_banner_and_no_card_fields(): void
    {
        [, $payment] = $this->scenario();
        $signed = app(PaymentGateway::class)->checkoutUrl($payment->external_id, new DateTimeImmutable('+30 minutes'));

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
        [, $payment] = $this->scenario();
        $signed = app(PaymentGateway::class)->checkoutUrl($payment->external_id, new DateTimeImmutable('+30 minutes'));

        $props = $this->get($signed)->assertOk()->viewData('page')['props'];

        $this->assertArrayHasKey('outcome_url', $props);
        $this->assertStringContainsString("/demo/pagos/{$payment->external_id}/resultado", $props['outcome_url']);
        $this->assertStringContainsString('signature=', $props['outcome_url']);
    }

    public function test_the_outcome_url_expiry_never_exceeds_the_bookings_remaining_payment_window(): void
    {
        // La ventana global de config permitiría bastante más de lo que le
        // queda a esta reserva puntual: el vencimiento firmado debe clamparse
        // al remanente de `payment_expires_at`, no al máximo de config.
        config(['payments.window_minutes' => 30]);
        [$booking, $payment] = $this->scenario(['payment_expires_at' => now()->addMinutes(5)]);

        $signed = app(PaymentGateway::class)->checkoutUrl($payment->external_id, new DateTimeImmutable('+30 minutes'));
        $props = $this->get($signed)->assertOk()->viewData('page')['props'];

        $expiresParam = (int) $this->queryParam($props['outcome_url'], 'expires');

        $this->assertLessThanOrEqual($booking->payment_expires_at->getTimestamp(), $expiresParam);
        $this->assertGreaterThan(now()->addMinutes(4)->getTimestamp(), $expiresParam);
    }

    private function queryParam(string $url, string $key): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query[$key] ?? null;
    }

    public function test_the_outcome_route_rejects_an_unsigned_post(): void
    {
        [, $payment] = $this->scenario();

        $this->post("/demo/pagos/{$payment->external_id}/resultado", ['outcome' => 'approved'])->assertForbidden();
    }

    public function test_approving_mutates_the_provider_and_queues_the_delivery(): void
    {
        Queue::fake();
        [, $payment] = $this->scenario();

        $this->post($this->outcomeUrl($payment->external_id), ['outcome' => 'approved'])->assertRedirect();

        $this->assertSame(
            PaymentStatus::Approved,
            SimulatedProviderPayment::where('external_id', $payment->external_id)->firstOrFail()->status,
        );
        Queue::assertPushed(DeliverSimulatedProviderWebhook::class, fn ($job) => $job->externalId === $payment->external_id
            && $job->status === PaymentStatus::Approved);
    }

    public function test_rejecting_mutates_the_provider_and_queues_the_delivery(): void
    {
        Queue::fake();
        [, $payment] = $this->scenario();

        $this->post($this->outcomeUrl($payment->external_id), ['outcome' => 'rejected'])->assertRedirect();

        $this->assertSame(
            PaymentStatus::Rejected,
            SimulatedProviderPayment::where('external_id', $payment->external_id)->firstOrFail()->status,
        );
        Queue::assertPushed(DeliverSimulatedProviderWebhook::class, fn ($job) => $job->status === PaymentStatus::Rejected);
    }

    public function test_abandoning_changes_nothing_and_queues_nothing(): void
    {
        Queue::fake();
        [, $payment] = $this->scenario();

        $this->post($this->outcomeUrl($payment->external_id), ['outcome' => 'abandoned'])->assertRedirect();

        $this->assertSame(
            PaymentStatus::Pending,
            SimulatedProviderPayment::where('external_id', $payment->external_id)->firstOrFail()->status,
        );
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_outcome_is_rejected(): void
    {
        Queue::fake();
        [, $payment] = $this->scenario();

        $this->post($this->outcomeUrl($payment->external_id), ['outcome' => 'refunded'])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_an_expired_provider_payment_cannot_be_approved(): void
    {
        Queue::fake();
        [$booking, $payment] = $this->scenario(['payment_expires_at' => now()->addSeconds(2)]);

        // El proveedor vence por su cuenta cuando se lo consulta (no hay
        // forma pública de retrasar `expires_at` del proveedor una vez creado
        // el checkout, así que se espera a que el reloj lo alcance).
        $this->travelTo(now()->addMinute());

        $this->post($this->outcomeUrl($payment->external_id), ['outcome' => 'approved'])->assertRedirect();

        $this->assertSame(
            PaymentStatus::Expired,
            SimulatedProviderPayment::where('external_id', $payment->external_id)->firstOrFail()->status,
        );
        Queue::assertNothingPushed();
    }

    public function test_acting_on_a_local_payment_that_is_already_terminal_is_a_safe_no_op(): void
    {
        Queue::fake();
        [, $payment] = $this->scenario();

        // Simula que una entrega anterior (webhook o reconciliación) ya
        // resolvió el pago local mientras esta pestaña de checkout seguía
        // abierta: el proveedor todavía está `pending` y aceptaría la
        // mutación, pero la aplicación ya lo considera cerrado.
        $payment->forceFill(['status' => PaymentStatus::Rejected])->save();

        $this->post($this->outcomeUrl($payment->external_id), ['outcome' => 'approved'])->assertRedirect();

        $this->assertSame(
            PaymentStatus::Pending,
            SimulatedProviderPayment::where('external_id', $payment->external_id)->firstOrFail()->status,
        );
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_external_id_is_a_404(): void
    {
        $this->post($this->outcomeUrl('sim_pay_nope'), ['outcome' => 'approved'])->assertNotFound();
    }
}
