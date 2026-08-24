import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';
import Button from '../../../Components/ui/Button';
import { FormField, Input, Select, Textarea } from '../../../Components/ui/Field';
import PageHeader from '../../../Components/ui/PageHeader';
import Surface from '../../../Components/ui/Surface';
import SlotPicker from '../../../Components/domain/SlotPicker';

function formatMoney(amount) {
    const value = Number(amount);
    if (!Number.isFinite(value) || value <= 0) return null;
    return `$${value.toLocaleString('es-AR')}`;
}

function SlotsSkeleton() {
    return (
        <div className="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6" aria-hidden="true">
            {Array.from({ length: 6 }).map((_, index) => (
                <div key={index} className="h-12 animate-pulse rounded border border-border bg-surface-disabled" />
            ))}
        </div>
    );
}

// Resumen lateral (§8.7): duración, precio y seña del servicio elegido, para
// que quede a la vista sin ir y volver a Servicios.
function BookingSummary({ service }) {
    if (!service) {
        return (
            <Surface className="p-5">
                <p className="text-[13px] leading-5 text-muted">Elegí un servicio para ver la duración, el precio y la seña.</p>
            </Surface>
        );
    }

    const price = formatMoney(service.price);
    const deposit = formatMoney(service.deposit_amount);

    return (
        <Surface className="p-5">
            <h2 className="mb-3 text-[15px] font-semibold">{service.name}</h2>
            <dl className="flex flex-col gap-2 text-[13px]">
                <div className="flex items-center justify-between">
                    <dt className="text-muted">Duración</dt>
                    <dd className="tnum font-medium">{service.duration_minutes} min</dd>
                </div>
                {price && (
                    <div className="flex items-center justify-between">
                        <dt className="text-muted">Precio</dt>
                        <dd className="tnum font-medium">{price}</dd>
                    </div>
                )}
                {deposit && (
                    <div className="flex items-center justify-between">
                        <dt className="text-muted">Seña</dt>
                        <dd className="tnum font-medium">{deposit}</dd>
                    </div>
                )}
            </dl>
        </Surface>
    );
}

export default function Form({ services, employees, slots }) {
    const { data, setData, post, processing, errors } = useForm({
        customer_email: '',
        service_id: '',
        employee_id: '',
        date: '',
        starts_at: '',
        notes: '',
    });
    const [loadingEmployees, setLoadingEmployees] = useState(false);
    const [loadingSlots, setLoadingSlots] = useState(false);

    // El desplegable de empleado se restringe a los asignados al servicio
    // elegido (mismo criterio que Public\BookingController::employeesFor):
    // sin esto, CreateBooking:45 recién rechaza la combinación al guardar.
    useEffect(() => {
        if (data.service_id) {
            router.reload({
                data: { service_id: data.service_id },
                only: ['employees'],
                onStart: () => setLoadingEmployees(true),
                onFinish: () => setLoadingEmployees(false),
            });
        }
    }, [data.service_id]);

    useEffect(() => {
        if (data.service_id && data.employee_id && data.date) {
            router.reload({
                data: { service_id: data.service_id, employee_id: data.employee_id, date: data.date },
                only: ['slots'],
                onStart: () => setLoadingSlots(true),
                onFinish: () => setLoadingSlots(false),
            });
        }
    }, [data.service_id, data.employee_id, data.date]);

    function submit(e) {
        e.preventDefault();
        post('/dashboard/bookings');
    }

    const selectedService = services.find((service) => String(service.id) === String(data.service_id)) ?? null;
    const canPickSlot = data.service_id && data.employee_id && data.date;

    return (
        <DashboardLayout>
            <PageHeader title="Nueva reserva" />

            <div className="max-w-[860px] lg:grid lg:grid-cols-[1fr_280px] lg:items-start lg:gap-6">
                {/* 390: el resumen colapsa arriba del formulario. */}
                <div className="order-1 mb-4 lg:order-2 lg:mb-0">
                    <BookingSummary service={selectedService} />
                </div>

                <div className="order-2 lg:order-1">
                    <Surface className="max-w-[560px] p-5">
                        <form onSubmit={submit} className="flex flex-col gap-4">
                            <FormField id="customer_email" label="Email del cliente" error={errors.customer_email}>
                                {(props) => (
                                    <Input
                                        {...props}
                                        type="email"
                                        value={data.customer_email}
                                        onChange={(e) => setData('customer_email', e.target.value)}
                                    />
                                )}
                            </FormField>

                            <FormField id="service_id" label="Servicio" error={errors.service_id}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={data.service_id}
                                        onChange={(e) => setData((d) => ({ ...d, service_id: e.target.value, employee_id: '', starts_at: '' }))}
                                    >
                                        <option value="">Elegir…</option>
                                        {services.map((service) => (
                                            <option key={service.id} value={service.id}>{service.name}</option>
                                        ))}
                                    </Select>
                                )}
                            </FormField>

                            <FormField id="employee_id" label="Empleado" error={errors.employee_id}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={data.employee_id}
                                        disabled={!data.service_id || loadingEmployees}
                                        onChange={(e) => setData((d) => ({ ...d, employee_id: e.target.value, starts_at: '' }))}
                                    >
                                        <option value="">
                                            {!data.service_id ? 'Elegí un servicio primero' : loadingEmployees ? 'Cargando…' : 'Elegir…'}
                                        </option>
                                        {employees.map((employee) => (
                                            <option key={employee.id} value={employee.id}>{employee.name}</option>
                                        ))}
                                    </Select>
                                )}
                            </FormField>

                            <FormField id="date" label="Fecha">
                                {(props) => (
                                    <Input
                                        {...props}
                                        type="date"
                                        value={data.date}
                                        onChange={(e) => setData((d) => ({ ...d, date: e.target.value, starts_at: '' }))}
                                    />
                                )}
                            </FormField>

                            <div>
                                <div className="mb-1.5 text-[13px] font-medium">Horario</div>
                                {!canPickSlot ? (
                                    <p className="text-[13px] leading-5 text-muted">Elegí servicio, empleado y fecha para ver los horarios disponibles.</p>
                                ) : loadingSlots ? (
                                    <SlotsSkeleton />
                                ) : (
                                    <>
                                        <SlotPicker slots={slots} value={data.starts_at} onChange={(value) => setData('starts_at', value)} />
                                        {slots.length === 0 && (
                                            <p className="mt-1.5 text-[13px] text-muted">Probá con otra fecha.</p>
                                        )}
                                    </>
                                )}
                                <InputError message={errors.starts_at} />
                            </div>

                            <FormField id="notes" label="Notas internas (opcional)" error={errors.notes}>
                                {(props) => (
                                    <Textarea
                                        {...props}
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                    />
                                )}
                            </FormField>

                            <Button type="submit" variant="primary" disabled={processing} className="w-full">
                                Guardar
                            </Button>
                        </form>
                    </Surface>
                </div>
            </div>
        </DashboardLayout>
    );
}
