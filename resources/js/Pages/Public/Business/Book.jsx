import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import InputError from '../../../Components/InputError';
import PublicLayout from '../../../Components/PublicLayout';
import Alert from '../../../Components/ui/Alert';
import Button from '../../../Components/ui/Button';
import EmptyState from '../../../Components/ui/EmptyState';
import Surface from '../../../Components/ui/Surface';
import { CheckIcon, ServiceIcon } from '../../../Components/ui/icons';
import SlotPicker from '../../../Components/domain/SlotPicker';

const STEP_ORDER = ['service', 'employee', 'date', 'slot'];
const STEP_LABELS = {
    service: 'Servicio',
    employee: 'Profesional',
    date: 'Fecha',
    slot: 'Horario disponible',
};
const WEEKDAY_LABELS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

function toDateValue(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function formatMoney(value, currency) {
    return new Intl.NumberFormat('es-AR', { style: 'currency', currency }).format(Number(value ?? 0));
}

function formatTime(iso) {
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatLongDate(dateValue) {
    const [year, month, day] = dateValue.split('-').map(Number);
    return new Date(year, month - 1, day).toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' });
}

// Fila de un paso ya resuelto: una línea con lo elegido y una acción para
// volver a abrirlo. El paso activo (el único abierto a la vez) se distingue
// con borde de tinta — no hay estado que comunicar, así que no hace falta un
// spine de color como en las reservas del calendario.
function CollapsedStep({ label, title, subtitle, onChange }) {
    return (
        <div className="rounded-md border border-border bg-surface">
            <div className="flex items-center gap-4 p-4 sm:p-5">
                <div className="micro w-20 flex-shrink-0 sm:w-24">{label}</div>
                <div className="min-w-0 flex-1">
                    <div className="truncate text-[15px] font-medium sm:text-[16px]">{title}</div>
                    {subtitle && <div className="tnum mt-0.5 truncate text-[13px] text-muted">{subtitle}</div>}
                </div>
                <button
                    type="button"
                    onClick={onChange}
                    className="flex-shrink-0 text-[13px] underline underline-offset-2 hover:text-fg"
                >
                    Cambiar
                </button>
            </div>
        </div>
    );
}

function StepShell({ label, children }) {
    return (
        <div className="overflow-hidden rounded-md border border-fg bg-surface">
            <div className="p-4 sm:p-5">
                <div className="micro">{label}</div>
                <div className="mt-3">{children}</div>
            </div>
        </div>
    );
}

function OptionRow({ selected, title, subtitle, onClick }) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={onClick}
            className={`flex w-full items-center justify-between gap-4 rounded border px-4 py-3 text-left ${
                selected ? 'border-fg bg-fg text-bg' : 'border-border bg-surface hover:border-fg-placeholder'
            }`}
        >
            <span className="min-w-0">
                <span className="block truncate text-[15px] font-medium">{title}</span>
                {subtitle && (
                    <span className={`tnum mt-0.5 block truncate text-[13px] ${selected ? 'text-fg-on-ink-muted' : 'text-muted'}`}>
                        {subtitle}
                    </span>
                )}
            </span>
            {selected && <CheckIcon size={16} className="flex-shrink-0" />}
        </button>
    );
}

export default function Book({ business, services, employees, slots, payment_window_minutes: paymentWindowMinutes }) {
    const { data, setData, post, processing, errors, clearErrors } = useForm({
        service_id: new URLSearchParams(window.location.search).get('service_id') ?? '',
        employee_id: '',
        date: '',
        starts_at: '',
    });
    const [loadingEmployees, setLoadingEmployees] = useState(false);
    const [loadingSlots, setLoadingSlots] = useState(false);
    const [openStep, setOpenStep] = useState(null);

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

    // El servidor puede rechazar starts_at si otra persona tomó el turno entre
    // que se pidió la grilla de horarios y que se envió el formulario. Cuando
    // eso pasa, se limpia el horario elegido y se refresca la grilla en lugar
    // de dejar seleccionado un turno que ya no existe.
    useEffect(() => {
        if (errors.starts_at) {
            setData((d) => ({ ...d, starts_at: '' }));
            setOpenStep('slot');
            if (data.service_id && data.employee_id && data.date) {
                router.reload({
                    data: { service_id: data.service_id, employee_id: data.employee_id, date: data.date },
                    only: ['slots'],
                });
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errors.starts_at]);

    const weekDates = useMemo(() => {
        const start = new Date();
        return Array.from({ length: 7 }, (_, index) => {
            const date = new Date(start);
            date.setDate(start.getDate() + index);
            return date;
        });
    }, []);
    const todayValue = toDateValue(new Date());

    function submit(e) {
        e.preventDefault();
        post(`/negocios/${business.slug}/reservar`);
    }

    const resolved = {
        service: Boolean(data.service_id),
        employee: Boolean(data.employee_id),
        date: Boolean(data.date),
        slot: Boolean(data.starts_at),
    };
    const firstUnresolvedIndex = STEP_ORDER.findIndex((key) => !resolved[key]);
    const activeIndex = openStep ? STEP_ORDER.indexOf(openStep) : firstUnresolvedIndex;
    const visibleSteps = activeIndex === -1 ? STEP_ORDER : STEP_ORDER.slice(0, activeIndex + 1);

    const selectedService = services.find((service) => String(service.id) === String(data.service_id));
    const selectedEmployee = employees.find((employee) => String(employee.id) === String(data.employee_id));
    const selectedSlot = slots.find((slot) => slot.starts_at === data.starts_at);

    const price = Number(selectedService?.price ?? 0);
    const depositAmount = Number(selectedService?.deposit_amount ?? 0);
    const hasDeposit = depositAmount > 0;
    const remainder = Math.max(price - depositAmount, 0);
    const allResolved = resolved.service && resolved.employee && resolved.date && resolved.slot;

    function stepBody(step) {
        if (step === 'service') {
            return (
                <>
                    <div role="radiogroup" aria-label="Servicio" className="flex flex-col gap-2">
                        {services.map((service) => (
                            <OptionRow
                                key={service.id}
                                selected={String(data.service_id) === String(service.id)}
                                title={service.name}
                                subtitle={`${service.duration_minutes} min · ${formatMoney(service.price, business.currency)}`}
                                onClick={() => {
                                    setData((d) => ({ ...d, service_id: String(service.id), employee_id: '', starts_at: '' }));
                                    setOpenStep(null);
                                }}
                            />
                        ))}
                    </div>
                    <InputError message={errors.service_id} />
                </>
            );
        }

        if (step === 'employee') {
            if (loadingEmployees) {
                return <p className="text-[13px] leading-5 text-muted">Buscando profesionales…</p>;
            }
            if (employees.length === 0) {
                return (
                    <p className="text-[13px] leading-5 text-muted">
                        Este servicio todavía no tiene profesionales asignados.
                    </p>
                );
            }
            return (
                <>
                    <div role="radiogroup" aria-label="Profesional" className="flex flex-col gap-2">
                        {employees.map((employee) => (
                            <OptionRow
                                key={employee.id}
                                selected={String(data.employee_id) === String(employee.id)}
                                title={employee.name}
                                onClick={() => {
                                    setData((d) => ({ ...d, employee_id: String(employee.id), starts_at: '' }));
                                    setOpenStep(null);
                                }}
                            />
                        ))}
                    </div>
                    <InputError message={errors.employee_id} />
                </>
            );
        }

        if (step === 'date') {
            return (
                <>
                    <div className="flex gap-2 overflow-x-auto pb-1">
                        {weekDates.map((date) => {
                            const value = toDateValue(date);
                            const isPast = value < todayValue;
                            const selected = data.date === value;
                            return (
                                <button
                                    key={value}
                                    type="button"
                                    disabled={isPast}
                                    onClick={() => setData((d) => ({ ...d, date: value, starts_at: '' }))}
                                    className={`flex h-16 w-[64px] flex-shrink-0 flex-col items-center justify-center gap-0.5 rounded border sm:w-[70px] ${
                                        selected
                                            ? 'border-fg bg-fg text-bg'
                                            : isPast
                                              ? 'cursor-not-allowed border-rule-faint bg-surface-disabled text-fg-placeholder'
                                              : 'border-border bg-surface hover:border-fg-placeholder'
                                    }`}
                                >
                                    <span className={`text-[12px] ${selected ? '' : isPast ? '' : 'text-muted'}`}>
                                        {WEEKDAY_LABELS[date.getDay()]}
                                    </span>
                                    <span className="tnum text-[17px] font-semibold">{date.getDate()}</span>
                                </button>
                            );
                        })}
                    </div>
                    <InputError message={errors.date} />
                </>
            );
        }

        // step === 'slot'
        if (loadingSlots) {
            return <p className="text-[13px] leading-5 text-muted">Buscando horarios disponibles…</p>;
        }
        return (
            <>
                {selectedEmployee && selectedService && (
                    <p className="mb-3 text-[13px] leading-5 text-muted">
                        Solo aparecen los horarios en los que {selectedEmployee.name} tiene {selectedService.duration_minutes}{' '}
                        minutos libres seguidos.
                    </p>
                )}
                <SlotPicker
                    slots={slots}
                    value={data.starts_at}
                    onChange={(value) => {
                        setData('starts_at', value);
                        setOpenStep(null);
                    }}
                />
                <InputError message={errors.starts_at} />
            </>
        );
    }

    return (
        <PublicLayout>
            <form
                onSubmit={submit}
                className={`mx-auto max-w-[1440px] px-6 pt-8 lg:px-10 lg:pt-10 lg:pb-10 ${allResolved ? 'pb-28 sm:pb-8' : 'pb-8'}`}
            >
                <nav aria-label="Guía" className="mb-3 flex items-center gap-2 text-[13px] text-muted">
                    <Link href="/negocios" className="hover:text-fg">Negocios</Link>
                    <span>/</span>
                    <Link href={`/negocios/${business.slug}`} className="hover:text-fg">{business.name}</Link>
                    <span>/</span>
                    <span className="text-fg">Reservar</span>
                </nav>

                <h1 className="mb-6 text-2xl font-semibold leading-8 tracking-[-0.02em] sm:text-[30px] sm:leading-[38px]">
                    Reservar en {business.name}
                </h1>

                {services.length === 0 ? (
                    <EmptyState
                        icon={ServiceIcon}
                        title="No hay servicios disponibles"
                        description="Todavía no hay servicios activos para reservar en este negocio."
                        action={<Button href={`/negocios/${business.slug}`} variant="secondary">Volver al negocio</Button>}
                    />
                ) : (
                    <div className="lg:grid lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start lg:gap-7">
                        <div className="flex flex-col gap-3.5">
                            {visibleSteps.map((step, index) => {
                                const isActive = activeIndex !== -1 && index === visibleSteps.length - 1;

                                if (!isActive) {
                                    const title =
                                        step === 'service'
                                            ? selectedService?.name
                                            : step === 'employee'
                                              ? selectedEmployee?.name
                                              : step === 'date'
                                                ? formatLongDate(data.date)
                                                : `${formatTime(selectedSlot?.starts_at)} – ${formatTime(selectedSlot?.ends_at)}`;
                                    const subtitle =
                                        step === 'service'
                                            ? `${selectedService?.duration_minutes} min · ${formatMoney(selectedService?.price, business.currency)}`
                                            : step === 'slot'
                                              ? formatLongDate(data.date)
                                              : undefined;

                                    return (
                                        <CollapsedStep
                                            key={step}
                                            label={STEP_LABELS[step]}
                                            title={title}
                                            subtitle={subtitle}
                                            onChange={() => {
                                                clearErrors();
                                                setOpenStep(step);
                                            }}
                                        />
                                    );
                                }

                                return (
                                    <StepShell key={step} label={STEP_LABELS[step]}>
                                        {stepBody(step)}
                                    </StepShell>
                                );
                            })}
                        </div>

                        <div className="mt-6 hidden sm:block lg:sticky lg:top-6 lg:mt-0">
                            <Surface className="overflow-hidden">
                                <div className="border-b border-border p-5">
                                    <div className="micro">Tu turno</div>
                                    {selectedService ? (
                                        <>
                                            <div className="mt-2 text-xl font-semibold tracking-[-0.015em]">{selectedService.name}</div>
                                            <div className="mt-0.5 text-[14px] text-muted">
                                                {business.name}
                                                {selectedEmployee ? ` · ${selectedEmployee.name}` : ''}
                                            </div>
                                            {selectedSlot ? (
                                                <>
                                                    <div className="tnum mt-3.5 flex items-baseline gap-2 text-[22px] font-semibold tracking-[-0.02em]">
                                                        <span>{formatTime(selectedSlot.starts_at)}</span>
                                                        <span className="text-[14px] font-normal text-muted">→</span>
                                                        <span>{formatTime(selectedSlot.ends_at)}</span>
                                                    </div>
                                                    <div className="tnum mt-0.5 text-[14px] text-muted">
                                                        {formatLongDate(data.date)} · {selectedService.duration_minutes} min
                                                    </div>
                                                </>
                                            ) : (
                                                <p className="mt-3.5 text-[13px] leading-5 text-muted">
                                                    Elegí un horario para ver el resumen completo.
                                                </p>
                                            )}
                                        </>
                                    ) : (
                                        <p className="mt-2 text-[13px] leading-5 text-muted">Elegí un servicio para empezar.</p>
                                    )}
                                </div>

                                {selectedService && (
                                    <div className="border-b border-border p-5">
                                        <div className="flex items-baseline justify-between gap-3">
                                            <span className="text-[14px] text-muted">Precio del servicio</span>
                                            <span className="tnum text-[15px] font-medium">{formatMoney(price, business.currency)}</span>
                                        </div>
                                        {hasDeposit && (
                                            <>
                                                <div className="mt-2 flex items-baseline justify-between gap-3">
                                                    <span className="text-[14px] font-medium">Seña a pagar ahora</span>
                                                    <span className="tnum text-[15px] font-semibold">
                                                        {formatMoney(depositAmount, business.currency)}
                                                    </span>
                                                </div>
                                                <div className="mt-2 flex items-baseline justify-between gap-3">
                                                    <span className="text-[14px] text-muted">Resto en el local</span>
                                                    <span className="tnum text-[15px] text-muted">
                                                        {formatMoney(remainder, business.currency)}
                                                    </span>
                                                </div>
                                            </>
                                        )}
                                    </div>
                                )}

                                {hasDeposit && (
                                    <div className="border-b border-border p-4">
                                        <Alert tone="warning" title="Este servicio pide seña">
                                            El turno queda reservado como <strong>pendiente</strong> y tenés {paymentWindowMinutes}{' '}
                                            minutos para pagar la seña. Si no se paga, se cancela solo y el horario vuelve a quedar
                                            libre.
                                        </Alert>
                                    </div>
                                )}

                                <div className="p-5">
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        size="lg"
                                        disabled={!allResolved || processing}
                                        className="w-full"
                                    >
                                        {hasDeposit ? 'Reservar y pagar la seña' : 'Confirmar reserva'}
                                    </Button>
                                    {hasDeposit && (
                                        <p className="mt-2.5 text-center text-xs leading-[18px] text-muted">
                                            Pago simulado. No se cobra nada y no hace falta ningún dato de tarjeta.
                                        </p>
                                    )}
                                    <p className="mt-2.5 text-[13px] leading-5 text-muted">
                                        Podés cancelar o reprogramar hasta {business.cancellation_hours} horas antes del turno.
                                    </p>
                                    <p className="mt-3.5 border-t border-border pt-3.5 text-xs leading-[19px] text-muted">
                                        Demo pública compartida: usá datos ficticios. El email de confirmación llega al{' '}
                                        <Link href="/como-funciona" className="text-fg underline">buzón de la demo</Link>, donde
                                        también hay mensajes de otras personas.
                                    </p>
                                </div>
                            </Surface>
                        </div>
                    </div>
                )}

                {allResolved && (
                    <div className="fixed inset-x-0 bottom-0 z-10 flex items-center gap-3 border-t border-border bg-surface px-4 py-3 sm:hidden">
                        <div className="min-w-0 flex-1">
                            <div className="tnum truncate text-[15px] font-semibold">
                                {formatTime(selectedSlot.starts_at)} – {formatTime(selectedSlot.ends_at)}
                            </div>
                            {hasDeposit && (
                                <div className="tnum truncate text-[12px] text-muted">
                                    Seña {formatMoney(depositAmount, business.currency)}
                                </div>
                            )}
                        </div>
                        <Button type="submit" variant="primary" size="lg" disabled={processing} className="flex-shrink-0">
                            {hasDeposit ? 'Reservar y pagar' : 'Confirmar'}
                        </Button>
                    </div>
                )}
            </form>
        </PublicLayout>
    );
}
