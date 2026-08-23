<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Enums\PaymentApplicationOutcome;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\ScheduleBreak;
use App\Models\Service;
use App\Models\User;
use App\Services\Payments\Simulated\SimulatedProviderPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dataset de demo determinista e idempotente: dos negocios, clientes,
 * horarios y reservas en los cuatro estados estables (confirmed, cancelled,
 * completed, no_show). Nunca siembra un estado `pending` — ni de reserva ni
 * de pago — porque `bookings:expire-unpaid` lo cancelaría solo minutos
 * después del reinicio, y el dataset dejaría de ser "conocido".
 *
 * Las reservas se construyen con factories y filas de `BookingStatusHistory`
 * escritas a mano, NUNCA con `App\Actions\Bookings\CreateBooking`: esa Action
 * rechaza horarios pasados dentro de su propia transacción y dispara
 * `BookingCreated` (efectos de notificación reales) fuera de ella.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->seedPeluqueria();
        $this->seedEstudio();
    }

    private function seedPeluqueria(): void
    {
        $slug = 'peluqueria-demo';

        // Idempotencia por negocio: un guard global impediría sembrar un
        // negocio nuevo en una instalación donde el primero ya existe.
        if (Business::where('slug', $slug)->exists()) {
            return;
        }

        DB::transaction(function (): void {
            $business = Business::create([
                'name' => 'Peluquería Demo',
                'slug' => 'peluqueria-demo',
                'timezone' => 'America/Argentina/Buenos_Aires',
                'currency' => 'ARS',
                'cancellation_hours' => 24,
                'is_active' => true,
            ]);

            User::factory()->create([
                'name' => 'Peluquería Demo Owner',
                'email' => 'owner@reservahub.test',
                'password' => 'password',
                'role' => Role::Owner,
                'business_id' => $business->id,
            ]);

            $employees = User::factory()
                ->count(2)
                ->sequence(
                    ['name' => 'Ana Empleada', 'email' => 'ana@reservahub.test'],
                    ['name' => 'Beto Empleado', 'email' => 'beto@reservahub.test'],
                )
                ->create([
                    'password' => 'password',
                    'role' => Role::Employee,
                    'business_id' => $business->id,
                ]);

            $ana = $employees->firstWhere('email', 'ana@reservahub.test');
            $beto = $employees->firstWhere('email', 'beto@reservahub.test');

            $services = Service::factory()
                ->for($business)
                ->count(5)
                ->sequence(
                    ['name' => 'Corte de cabello', 'duration_minutes' => 30, 'buffer_minutes' => 5, 'price' => 3500],
                    ['name' => 'Coloración', 'duration_minutes' => 90, 'buffer_minutes' => 15, 'price' => 12000, 'deposit_amount' => 3000],
                    ['name' => 'Manicura', 'duration_minutes' => 45, 'buffer_minutes' => 5, 'price' => 4000],
                    ['name' => 'Masaje', 'duration_minutes' => 60, 'buffer_minutes' => 10, 'price' => 8000],
                    ['name' => 'Depilación', 'duration_minutes' => 30, 'buffer_minutes' => 10, 'price' => 5000],
                )
                ->create();

            $corte = $services->firstWhere('name', 'Corte de cabello');
            $coloracion = $services->firstWhere('name', 'Coloración');
            $manicura = $services->firstWhere('name', 'Manicura');
            $masaje = $services->firstWhere('name', 'Masaje');
            $depilacion = $services->firstWhere('name', 'Depilación');

            foreach ([$ana, $beto] as $employee) {
                $this->seedWeeklySchedule($business, $employee, DayOfWeek::cases());
                $employee->services()->sync($services->pluck('id'));
            }

            $customers = $this->seedCustomers([
                'marina' => ['name' => 'Marina Cliente', 'email' => 'marina@reservahub.test'],
                'lucia' => ['name' => 'Lucía Cliente', 'email' => 'lucia@reservahub.test'],
                'rodrigo' => ['name' => 'Rodrigo Cliente', 'email' => 'rodrigo@reservahub.test'],
                'julian' => ['name' => 'Julián Cliente', 'email' => 'julian@reservahub.test'],
            ]);

            $today = CarbonImmutable::now($business->timezone)->startOfDay();

            // Reservas de HOY: una sola columna, en orden y sin superposición
            // (verificado por DemoSeederTest::test_todays_bookings_...).
            $todayBookings = [
                ['09:00', $corte, $ana, $customers['marina'], BookingStatus::Confirmed],
                ['10:00', $coloracion, $ana, $customers['lucia'], BookingStatus::Confirmed],
                ['12:00', $corte, $beto, $customers['rodrigo'], BookingStatus::Confirmed],
                ['15:00', $manicura, $ana, $customers['rodrigo'], BookingStatus::Confirmed],
                ['16:30', $masaje, $beto, $customers['marina'], BookingStatus::Confirmed],
                ['17:30', $depilacion, $ana, $customers['julian'], BookingStatus::Cancelled],
            ];

            foreach ($todayBookings as [$time, $service, $employee, $customer, $status]) {
                $startsAtLocal = $this->atTime($today, $time);

                if ($service->deposit_amount !== null) {
                    $this->seedDepositBookingWithPayment(
                        $business, $employee, $customer, $service, $startsAtLocal, 'sim_pay_demo_coloracion_hoy'
                    );

                    continue;
                }

                $this->seedPlainBooking($business, $employee, $customer, $service, $startsAtLocal, $status);
            }

            // Reservas pasadas: la historia detrás del "hoy" (completadas y
            // ausencias, más alguna cancelación).
            $pastBookings = [
                [-3, '09:00', $corte, $ana, $customers['marina'], BookingStatus::Completed],
                [-3, '11:00', $manicura, $ana, $customers['lucia'], BookingStatus::Completed],
                [-3, '14:00', $masaje, $beto, $customers['rodrigo'], BookingStatus::NoShow],
                [-2, '09:30', $depilacion, $beto, $customers['julian'], BookingStatus::Completed],
                [-2, '12:00', $corte, $ana, $customers['rodrigo'], BookingStatus::NoShow],
                [-2, '16:00', $masaje, $ana, $customers['marina'], BookingStatus::Completed],
                [-1, '10:00', $manicura, $beto, $customers['lucia'], BookingStatus::Completed],
                [-1, '14:30', $corte, $ana, $customers['julian'], BookingStatus::Cancelled],
                [-1, '17:00', $depilacion, $beto, $customers['marina'], BookingStatus::Completed],
            ];

            foreach ($pastBookings as [$dayOffset, $time, $service, $employee, $customer, $status]) {
                $startsAtLocal = $this->atTime($today->addDays($dayOffset), $time);

                $this->seedPlainBooking($business, $employee, $customer, $service, $startsAtLocal, $status);
            }

            // Reservas futuras: agenda ya confirmada, incluida una seña ya paga.
            $futureBookings = [
                [1, '09:00', $corte, $ana, $customers['marina'], BookingStatus::Confirmed],
                [1, '11:00', $manicura, $beto, $customers['lucia'], BookingStatus::Confirmed],
                [1, '15:00', $depilacion, $ana, $customers['rodrigo'], BookingStatus::Confirmed],
                [2, '14:00', $masaje, $beto, $customers['julian'], BookingStatus::Confirmed],
                [2, '16:00', $corte, $ana, $customers['marina'], BookingStatus::Confirmed],
            ];

            foreach ($futureBookings as [$dayOffset, $time, $service, $employee, $customer, $status]) {
                $startsAtLocal = $this->atTime($today->addDays($dayOffset), $time);

                $this->seedPlainBooking($business, $employee, $customer, $service, $startsAtLocal, $status);
            }

            $this->seedDepositBookingWithPayment(
                $business,
                $ana,
                $customers['lucia'],
                $coloracion,
                $this->atTime($today->addDays(2), '10:00'),
                'sim_pay_demo_coloracion_futura',
            );
        });
    }

    private function seedEstudio(): void
    {
        $slug = 'estudio-demo';

        if (Business::where('slug', $slug)->exists()) {
            return;
        }

        DB::transaction(function (): void {
            $business = Business::create([
                'name' => 'Estudio Demo',
                'slug' => 'estudio-demo',
                'timezone' => 'America/Argentina/Buenos_Aires',
                'currency' => 'ARS',
                'cancellation_hours' => 24,
                'is_active' => true,
            ]);

            User::factory()->create([
                'name' => 'Estudio Demo Owner',
                'email' => 'owner2@reservahub.test',
                'password' => 'password',
                'role' => Role::Owner,
                'business_id' => $business->id,
            ]);

            $carla = User::factory()->create([
                'name' => 'Carla Empleada',
                'email' => 'carla@reservahub.test',
                'password' => 'password',
                'role' => Role::Employee,
                'business_id' => $business->id,
            ]);

            $services = Service::factory()
                ->for($business)
                ->count(2)
                ->sequence(
                    ['name' => 'Clase de guitarra', 'duration_minutes' => 60, 'buffer_minutes' => 10, 'price' => 6000],
                    ['name' => 'Grabación de demo', 'duration_minutes' => 120, 'buffer_minutes' => 30, 'price' => 20000, 'deposit_amount' => 5000],
                )
                ->create();

            $clase = $services->firstWhere('name', 'Clase de guitarra');
            $grabacion = $services->firstWhere('name', 'Grabación de demo');

            $this->seedWeeklySchedule($business, $carla, [
                DayOfWeek::Monday,
                DayOfWeek::Tuesday,
                DayOfWeek::Wednesday,
                DayOfWeek::Thursday,
                DayOfWeek::Friday,
            ]);
            $carla->services()->sync($services->pluck('id'));

            $customers = $this->seedCustomers([
                'valentina' => ['name' => 'Valentina Cliente', 'email' => 'valentina@reservahub.test'],
                'nico' => ['name' => 'Nico Cliente', 'email' => 'nico@reservahub.test'],
            ]);

            $today = CarbonImmutable::now($business->timezone)->startOfDay();

            // Las dos reservas de Estudio se ajustan al día hábil más cercano:
            // Carla solo trabaja de lunes a viernes.
            $this->seedPlainBooking(
                $business,
                $carla,
                $customers['valentina'],
                $clase,
                $this->atTime($this->nearestBusinessDay($today), '11:00'),
                BookingStatus::Confirmed,
            );

            $this->seedDepositBookingWithPayment(
                $business,
                $carla,
                $customers['nico'],
                $grabacion,
                $this->atTime($this->nearestBusinessDay($today->addDays(2)), '16:00'),
                'sim_pay_demo_grabacion',
            );
        });
    }

    /**
     * @param  array<int, DayOfWeek>  $days
     */
    private function seedWeeklySchedule(Business $business, User $employee, array $days): void
    {
        foreach ($days as $day) {
            $schedule = Schedule::factory()->for($business)->create([
                'employee_id' => $employee->id,
                'day_of_week' => $day,
                'start_time' => '09:00',
                'end_time' => '18:00',
            ]);

            ScheduleBreak::create([
                'schedule_id' => $schedule->id,
                'start_time' => '13:00',
                'end_time' => '14:00',
            ]);
        }
    }

    /**
     * @param  array<string, array{name: string, email: string}>  $definitions
     * @return array<string, User>
     */
    private function seedCustomers(array $definitions): array
    {
        $customers = [];

        foreach ($definitions as $key => $attributes) {
            $customers[$key] = User::factory()->customer()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => 'password',
            ]);
        }

        return $customers;
    }

    private function atTime(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $day->setTime($hour, $minute);
    }

    /**
     * Rueda hacia adelante hasta el próximo día hábil (lunes a viernes),
     * dejando el resto de la fecha sin tocar.
     */
    private function nearestBusinessDay(CarbonImmutable $date): CarbonImmutable
    {
        return match ($date->dayOfWeekIso) {
            6 => $date->addDays(2), // sábado -> lunes
            7 => $date->addDay(),   // domingo -> lunes
            default => $date,
        };
    }

    /**
     * Reserva sin seña: arranca directamente `confirmed` (nunca pasó por
     * `pending`, porque el servicio no la exige) y, si el estado final es
     * otro, se agrega una segunda fila de historial para esa transición.
     */
    private function seedPlainBooking(
        Business $business,
        User $employee,
        User $customer,
        Service $service,
        CarbonImmutable $startsAtLocal,
        BookingStatus $finalStatus,
    ): Booking {
        $endsAtLocal = $startsAtLocal->addMinutes($service->duration_minutes);

        $factory = match ($finalStatus) {
            BookingStatus::Confirmed => Booking::factory()->confirmed(),
            BookingStatus::Cancelled => Booking::factory()->cancelled(),
            BookingStatus::Completed => Booking::factory()->completed(),
            BookingStatus::NoShow => Booking::factory()->noShow(),
            BookingStatus::Pending => throw new \InvalidArgumentException(
                'El DemoSeeder nunca siembra reservas pendientes: se cancelarían solas.'
            ),
        };

        $booking = $factory->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $startsAtLocal->utc(),
            'ends_at' => $endsAtLocal->utc(),
            'price' => $service->price,
            'deposit_amount' => null,
            'source' => 'web',
            'cancelled_at' => $finalStatus === BookingStatus::Cancelled ? $startsAtLocal->utc() : null,
        ]);

        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => null,
            'to_status' => BookingStatus::Confirmed,
        ]);

        if ($finalStatus !== BookingStatus::Confirmed) {
            BookingStatusHistory::create([
                'booking_id' => $booking->id,
                'from_status' => BookingStatus::Confirmed,
                'to_status' => $finalStatus,
            ]);
        }

        return $booking;
    }

    /**
     * Reserva con seña: el historial refleja que arrancó `pending` y se
     * confirmó por un pago aprobado, con su fila propia en
     * `simulated_provider_payments` (sin ella, `fetchPayment()` del gateway
     * simulado devuelve 404).
     */
    private function seedDepositBookingWithPayment(
        Business $business,
        User $employee,
        User $customer,
        Service $service,
        CarbonImmutable $startsAtLocal,
        string $paymentExternalId,
    ): Booking {
        $endsAtLocal = $startsAtLocal->addMinutes($service->duration_minutes);
        $paidAtUtc = $startsAtLocal->subDay()->utc();

        $booking = Booking::factory()->confirmed()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'service_id' => $service->id,
            'starts_at' => $startsAtLocal->utc(),
            'ends_at' => $endsAtLocal->utc(),
            'price' => $service->price,
            'deposit_amount' => $service->deposit_amount,
            'source' => 'web',
        ]);

        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => null,
            'to_status' => BookingStatus::Pending,
        ]);

        SimulatedProviderPayment::create([
            'external_id' => $paymentExternalId,
            'status' => PaymentStatus::Approved,
            'amount' => $service->deposit_amount,
            'currency' => $business->currency,
            'approved_at' => $paidAtUtc,
            'expires_at' => $paidAtUtc->addMinutes(30),
            'payload' => [
                'payment_id' => $paymentExternalId,
                'status' => PaymentStatus::Approved->value,
                'amount' => (string) $service->deposit_amount,
                'currency' => $business->currency,
            ],
        ]);

        $payment = Payment::create([
            'business_id' => $business->id,
            'booking_id' => $booking->id,
            'provider' => 'simulated',
            'external_id' => $paymentExternalId,
            'status' => PaymentStatus::Approved,
            'amount' => $service->deposit_amount,
            'currency' => $business->currency,
            'expires_at' => $paidAtUtc->addMinutes(30),
            'paid_at' => $paidAtUtc,
            'applied_at' => $paidAtUtc,
            'application_outcome' => PaymentApplicationOutcome::BookingConfirmed,
            'last_snapshot' => ['status' => PaymentStatus::Approved->value],
        ]);

        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => BookingStatus::Pending,
            'to_status' => BookingStatus::Confirmed,
            'notes' => "Confirmada por pago aprobado #{$payment->id}.",
        ]);

        return $booking;
    }
}
