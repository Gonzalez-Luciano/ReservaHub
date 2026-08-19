<?php

namespace Tests\Feature\Payments;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\CheckoutRequest;
use App\Services\Payments\Data\WebhookEnvelope;
use App\Services\Payments\ProcessPaymentWebhook;
use App\Services\Payments\Simulated\SimulatedPaymentGateway;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

/**
 * No todos los tests de este archivo son concurrencia genuina en el sentido
 * literal de "dos sesiones peleando por la misma fila al mismo tiempo" — cada
 * uno prueba el invariante correcto para lo que realmente ejercita, y el
 * nombre de cada test describe con precisión cuál es:
 *
 * - `test_a_second_session_cannot_process_the_event_while_the_first_holds_it`
 *   es la única prueba de concurrencia genuina: dos sesiones reales de
 *   PostgreSQL con el lock de la primera todavía sostenido cuando la segunda
 *   intenta tomarlo.
 * - `test_two_sessions_cannot_both_claim_the_same_event_identity` usa dos
 *   sesiones PDO reales, pero la sesión A confirma antes de que la sesión B
 *   inserte — prueba que el índice único rechaza un duplicado contra una fila
 *   ya confirmada, no dos sesiones compitiendo mientras ambas están sin
 *   confirmar (ese angostamiento fue una corrección deliberada para evitar un
 *   deadlock real; no es un descuido).
 * - `test_two_pending_payments_for_one_booking_are_impossible` usa una única
 *   sesión PDO cruda insertando contra una fila ya confirmada desde la
 *   conexión principal — no dos sesiones entrelazadas.
 * - `test_webhook_and_reconciliation_converge_on_a_single_confirmation` y
 *   `test_expiry_and_approval_at_the_boundary_never_both_apply` no usan
 *   ninguna sesión PDO cruda: son secuenciales, en una sola conexión, y
 *   prueban convergencia idempotente entre el webhook y la reconciliación (o
 *   entre el webhook y `expire-unpaid`), no contención real de locks.
 * - `test_expiry_wins_when_the_provider_never_resolves` también es
 *   secuencial: aplica la expiración del lado del proveedor directamente
 *   sobre el pago para simular lo que la reconciliación observaría más
 *   adelante, sin invocar `payments:reconcile`.
 */
class WebhookConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function rawConnection(): PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);

        return new PDO($dsn, $config['username'], $config['password']);
    }

    /**
     * @return array{0: Booking, 1: Payment}
     */
    private function scenario(): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC', 'currency' => 'ARS']);
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->for($business)->create(['deposit_amount' => '10.00']);

        $booking = Booking::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Pending,
            'deposit_amount' => '10.00',
            'payment_expires_at' => now()->addMinutes(30),
        ]);

        $checkout = app(PaymentGateway::class)->createCheckout(new CheckoutRequest(
            reference: (string) Str::ulid(),
            amount: '10.00',
            currency: 'ARS',
            description: 'Seña',
            returnUrl: 'http://localhost/mis-reservas',
            expiresAt: new DateTimeImmutable('+30 minutes'),
        ));

        $payment = Payment::factory()->for($booking)->create([
            'business_id' => $business->id,
            'status' => PaymentStatus::Pending,
            'external_id' => $checkout->externalId,
            'amount' => '10.00',
            'currency' => 'ARS',
        ]);

        return [$booking, $payment];
    }

    private function envelope(Payment $payment, PaymentStatus $status, string $eventId): WebhookEnvelope
    {
        /** @var SimulatedPaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);

        $rawBody = json_encode([
            'event_id' => $eventId,
            'payment_id' => $payment->external_id,
            'status' => $status->value,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'occurred_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        return new WebhookEnvelope($rawBody, [
            'X-ReservaHub-Signature' => $gateway->signatureHeaderFor($rawBody),
        ]);
    }

    public function test_two_sessions_cannot_both_claim_the_same_event_identity(): void
    {
        [, $payment] = $this->scenario();

        $sessionA = $this->rawConnection();
        $sessionB = $this->rawConnection();

        $insert = 'insert into webhook_events (provider, external_event_id, payment_external_id, payload, status, attempts, received_at, created_at, updated_at)
                   values (:provider, :event, :payment, :payload::jsonb, :status, 0, now(), now(), now())';

        $bindings = [
            'provider' => 'simulated',
            'event' => 'evt_concurrent',
            'payment' => $payment->external_id,
            'payload' => json_encode(['status' => 'approved']),
            'status' => 'received',
        ];

        $sessionA->beginTransaction();
        $sessionA->prepare($insert)->execute($bindings);
        $sessionA->commit();

        $sessionB->beginTransaction();
        $statement = $sessionB->prepare($insert.' on conflict (provider, external_event_id) do nothing');
        $statement->execute($bindings);
        $insertedByB = $statement->rowCount();
        $sessionB->commit();

        // B no pudo insertar: la identidad del evento la garantiza PostgreSQL.
        $this->assertSame(0, $insertedByB);
        $this->assertSame(1, WebhookEvent::where('external_event_id', 'evt_concurrent')->count());
    }

    public function test_a_second_session_cannot_process_the_event_while_the_first_holds_it(): void
    {
        [, $payment] = $this->scenario();

        WebhookEvent::create([
            'provider' => 'simulated',
            'external_event_id' => 'evt_locked',
            'payment_external_id' => $payment->external_id,
            'payload' => ['status' => 'approved'],
            'status' => 'received',
            'attempts' => 0,
            'received_at' => now(),
        ]);

        $sessionA = $this->rawConnection();
        $sessionB = $this->rawConnection();

        $sessionA->beginTransaction();
        $sessionA->exec("select id from webhook_events where external_event_id = 'evt_locked' for update");

        $sessionB->beginTransaction();
        $locked = $sessionB->query("select id from webhook_events where external_event_id = 'evt_locked' for update skip locked")->fetchColumn();
        $sessionB->commit();

        $this->assertFalse($locked, 'La fila del evento debe quedar bloqueada mientras otra sesión la procesa.');

        $sessionA->commit();

        $sessionB->beginTransaction();
        $afterRelease = $sessionB->query("select id from webhook_events where external_event_id = 'evt_locked' for update skip locked")->fetchColumn();
        $sessionB->commit();

        $this->assertNotFalse($afterRelease);
    }

    public function test_two_pending_payments_for_one_booking_are_impossible(): void
    {
        [$booking, $payment] = $this->scenario();

        $session = $this->rawConnection();
        $session->beginTransaction();

        $statement = $session->prepare(
            "insert into payments (business_id, booking_id, provider, external_id, status, amount, currency, expires_at, created_at, updated_at)
             values (:business, :booking, 'simulated', :external, 'pending', 10.00, 'ARS', now() + interval '30 minutes', now(), now())"
        );

        $failed = false;

        try {
            $statement->execute([
                'business' => $booking->business_id,
                'booking' => $booking->id,
                'external' => 'sim_pay_second_live',
            ]);
        } catch (\PDOException) {
            $failed = true;
        }

        $session->rollBack();

        $this->assertTrue($failed, 'El índice único parcial debe impedir dos intentos vivos por reserva.');
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('booking_id', $booking->id)->count());
    }

    public function test_webhook_and_reconciliation_converge_on_a_single_confirmation(): void
    {
        [$booking, $payment] = $this->scenario();
        /** @var SimulatedPaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);
        $gateway->applyOutcome($payment->external_id, PaymentStatus::Approved);

        app(ProcessPaymentWebhook::class)->handle($this->envelope($payment, PaymentStatus::Approved, 'evt_race'));
        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->assertSame(1, $booking->statusHistories()->where('to_status', BookingStatus::Confirmed)->count());
        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
    }

    public function test_expiry_and_approval_at_the_boundary_never_both_apply(): void
    {
        [$booking, $payment] = $this->scenario();
        /** @var SimulatedPaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);
        $gateway->applyOutcome($payment->external_id, PaymentStatus::Approved);

        // Secuencial, no una carrera real: la ventana ya está vencida cuando
        // se aplica la aprobación, para comprobar que `expire-unpaid` (que
        // corre después, también secuencial) ya no encuentra nada que cancelar.
        $booking->forceFill(['payment_expires_at' => now()->subSecond()])->save();

        app(ProcessPaymentWebhook::class)->handle($this->envelope($payment, PaymentStatus::Approved, 'evt_boundary'));
        $this->artisan('bookings:expire-unpaid')->assertExitCode(0);

        $booking->refresh();

        // Confirmada XOR cancelada, nunca ambos efectos.
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(0, $booking->statusHistories()->where('to_status', BookingStatus::Cancelled)->count());
    }

    public function test_expiry_wins_when_the_provider_never_resolves(): void
    {
        [$booking, $payment] = $this->scenario();

        // El intento sigue vivo: expire-unpaid NO debe cancelar todavía.
        $booking->forceFill(['payment_expires_at' => now()->subMinute()])->save();
        $this->artisan('bookings:expire-unpaid')->assertExitCode(0);
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);

        // El estado se fuerza directamente en vez de invocar
        // `payments:reconcile`: simula lo que el proveedor reportaría (y lo
        // que la reconciliación eventualmente observaría), sin ejercer el
        // comando en sí — recién con esto puesto se libera la cancelación.
        $payment->forceFill(['status' => PaymentStatus::Expired])->save();
        $this->artisan('bookings:expire-unpaid')->assertExitCode(0);

        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
    }
}
