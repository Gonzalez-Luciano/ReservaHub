import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import BookingsRealtime from '../../../Components/BookingsRealtime';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';
import Button from '../../../Components/ui/Button';
import { Input } from '../../../Components/ui/Field';
import Modal from '../../../Components/ui/Modal';
import Toast from '../../../Components/ui/Toast';
import TableShell from '../../../Components/ui/TableShell';
import EmptyState from '../../../Components/ui/EmptyState';
import PageHeader from '../../../Components/ui/PageHeader';
import BookingStatusBadge, { BOOKING_SPINE } from '../../../Components/domain/BookingStatusBadge';
import BookingActions from '../../../Components/domain/BookingActions';
import SlotPicker from '../../../Components/domain/SlotPicker';
import { CalendarIcon, PlusIcon } from '../../../Components/ui/icons';

const STATUS_OPTIONS = [
    { value: '', label: 'Todos' },
    { value: 'pending', label: 'Pendiente' },
    { value: 'confirmed', label: 'Confirmada' },
    { value: 'completed', label: 'Completada' },
    { value: 'cancelled', label: 'Cancelada' },
    { value: 'no_show', label: 'Ausencia' },
];

// Constante de módulo: el callback de useEcho dentro de BookingsRealtime
// queda memoizado en el primer render, así que conviene que capture siempre
// el mismo array. No se toca el contrato del componente (Fase 10).
const RELOAD_ONLY = ['bookings'];

// Mismo guard que ya existía: sin VITE_REVERB_APP_KEY compilado, pusher-js
// lanza al construirse. Montar el suscriptor solo cuando hay configuración
// deja la página utilizable siempre.
const realtimeEnabled = Boolean(import.meta.env.VITE_REVERB_APP_KEY);

function formatMoney(amount) {
    const value = Number(amount);
    if (!Number.isFinite(value) || value <= 0) return null;
    return `$${value.toLocaleString('es-AR')}`;
}

// Las reservas ya llegan del servidor ordenadas asc por starts_at
// (desempatadas por ends_at y por id). Agrupar por `date_key` preserva ese
// orden dentro de cada día; el reordenamiento de grupos (hoy, futuras asc,
// pasadas desc al final) es el único trabajo de esta función.
function groupByDay(bookings) {
    const order = [];
    const groups = new Map();

    for (const booking of bookings) {
        if (!groups.has(booking.date_key)) {
            groups.set(booking.date_key, {
                date_key: booking.date_key,
                weekday: booking.weekday,
                day_month: booking.day_month,
                day_bucket: booking.day_bucket,
                bookings: [],
            });
            order.push(booking.date_key);
        }
        groups.get(booking.date_key).bookings.push(booking);
    }

    const list = order.map((key) => groups.get(key));
    const today = list.filter((group) => group.day_bucket === 'today');
    const future = list.filter((group) => group.day_bucket === 'future');
    const past = list.filter((group) => group.day_bucket === 'past').reverse();

    return [...today, ...future, ...past];
}

function DayHeading({ group }) {
    const label = group.day_bucket === 'today'
        ? `Hoy · ${group.weekday.toLowerCase()} ${group.day_month}`
        : `${group.weekday} ${group.day_month}`;

    return (
        <div className="mb-2 flex items-center gap-2.5">
            <span className="micro">{label}</span>
            <div className="h-px flex-grow bg-border" aria-hidden="true" />
        </div>
    );
}

function BookingRow({ booking, onReschedule }) {
    const cancelled = booking.status === 'cancelled';
    const deposit = formatMoney(booking.deposit_amount);
    const total = formatMoney(booking.price);
    const showExpiry = booking.status === 'pending' && booking.payment_expires_at_time;

    return (
        <div className="grid grid-cols-[3px_minmax(0,1fr)]">
            <div className={BOOKING_SPINE[booking.status] ?? 'bg-cancelled-fg'} />
            <div className="flex flex-col gap-2 px-4 py-3 lg:grid lg:grid-cols-[68px_minmax(0,1fr)_140px_140px_180px_auto] lg:items-center lg:gap-3">
                <div className="flex items-center justify-between gap-2 lg:block">
                    {/* La hora tachada es la señal de "cancelada" en cualquier lugar de la
                        app donde se muestra junto al estado (mismo criterio que DayRail). */}
                    <span className={`tnum text-[15px] font-semibold tracking-[-0.01em] ${cancelled ? 'text-muted line-through' : ''}`}>
                        {booking.starts_at_time}
                    </span>
                    <span className="lg:hidden"><BookingStatusBadge status={booking.status} /></span>
                </div>

                <div className="min-w-0">
                    <div className={`truncate text-[15px] font-medium ${cancelled ? 'text-muted' : ''}`}>
                        {booking.service_name}
                        {cancelled && ' — cancelada'}
                    </div>
                    <div className="tnum text-[12px] text-muted">{booking.duration_minutes} min</div>
                    {deposit && (
                        <div className="tnum text-[12px] text-muted">
                            Seña {deposit}{total ? ` de ${total}` : ''}
                        </div>
                    )}
                    <div className="mt-0.5 truncate text-[13px] text-muted lg:hidden">
                        {booking.employee_name} · {booking.customer_name}
                    </div>
                </div>

                <div className="hidden truncate text-[13px] text-fg lg:block">{booking.employee_name}</div>
                <div className="hidden truncate text-[13px] text-fg lg:block">{booking.customer_name}</div>

                <div className="hidden lg:block">
                    <BookingStatusBadge status={booking.status} />
                    {showExpiry && (
                        <div className="mt-1 text-[12px] font-medium text-pending-fg">Vence {booking.payment_expires_at_time}</div>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Link href={`/dashboard/bookings/${booking.id}`} className="text-[13px]">Ver</Link>
                    <BookingActions booking={booking} onReschedule={() => onReschedule(booking)} />
                </div>
            </div>
        </div>
    );
}

function FilterBar({ filters, employees, hasFilters, total, onChange, onClear }) {
    return (
        <div className="mb-5 flex flex-wrap items-center gap-2">
            <label className="inline-flex h-[34px] items-center gap-2 rounded border border-border bg-surface px-3 text-[13px]">
                <span className="text-muted">Estado</span>
                <select
                    value={filters.status ?? ''}
                    onChange={(event) => onChange('status', event.target.value)}
                    className="bg-transparent font-medium focus:outline-none"
                >
                    {STATUS_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                </select>
            </label>

            <label className="inline-flex h-[34px] items-center gap-2 rounded border border-border bg-surface px-3 text-[13px]">
                <span className="text-muted">Personal</span>
                <select
                    value={filters.employee_id ?? ''}
                    onChange={(event) => onChange('employee_id', event.target.value)}
                    className="bg-transparent font-medium focus:outline-none"
                >
                    <option value="">Todos</option>
                    {employees.map((employee) => (
                        <option key={employee.id} value={employee.id}>{employee.name}</option>
                    ))}
                </select>
            </label>

            <label className="inline-flex h-[34px] items-center gap-2 rounded border border-border bg-surface px-3 text-[13px]">
                <span className="text-muted">Desde</span>
                <input
                    type="date"
                    value={filters.from ?? ''}
                    onChange={(event) => onChange('from', event.target.value)}
                    className="bg-transparent font-medium focus:outline-none"
                />
            </label>

            {hasFilters && (
                <button type="button" onClick={onClear} className="text-[13px] underline">Limpiar filtros</button>
            )}

            <div className="flex-grow" />
            <div className="tnum text-[13px] text-muted">{total} {total === 1 ? 'reserva' : 'reservas'}</div>
        </div>
    );
}

export default function Index({ bookings, employees, businessId, filters }) {
    const { errors } = usePage().props;

    const [reschedulingBooking, setReschedulingBooking] = useState(null);
    const [rescheduleDate, setRescheduleDate] = useState('');
    const [rescheduleSlots, setRescheduleSlots] = useState([]);
    const [rescheduleStartsAt, setRescheduleStartsAt] = useState('');
    const [loadingSlots, setLoadingSlots] = useState(false);
    const [toastMessage, setToastMessage] = useState(null);

    // BookingsRealtime dispara `router.reload({ only: ['bookings'], ... })`
    // sin avisarle a nadie más: no expone ningún callback (y no hay que
    // agregarle uno — su contrato queda intacto). Para mostrar el toast solo
    // cuando ESA recarga específica termina, y no en cualquier otra visita
    // Inertia de la página (filtros, acciones de reserva), se escucha el
    // bus global de eventos del router y se usa `only` como huella: es la
    // única recarga de esta pantalla que pide justamente `['bookings']`.
    const realtimeReload = useRef(false);

    useEffect(() => {
        const stopStart = router.on('start', (event) => {
            realtimeReload.current = Boolean(event.detail.visit.only?.includes('bookings'));
        });
        const stopSuccess = router.on('success', () => {
            if (realtimeReload.current) {
                setToastMessage('Las reservas se actualizaron');
            }
            realtimeReload.current = false;
        });

        return () => {
            stopStart();
            stopSuccess();
        };
    }, []);

    function applyFilters(patch) {
        const next = { ...filters, ...patch };
        Object.keys(next).forEach((key) => {
            if (!next[key]) delete next[key];
        });
        router.get('/dashboard/bookings', next, { preserveState: true, preserveScroll: true, replace: true });
    }

    function clearFilters() {
        router.get('/dashboard/bookings', {}, { preserveState: true, preserveScroll: true, replace: true });
    }

    function openReschedule(booking) {
        setReschedulingBooking(booking);
        setRescheduleDate('');
        setRescheduleSlots([]);
        setRescheduleStartsAt('');
    }

    function closeReschedule() {
        setReschedulingBooking(null);
    }

    async function onRescheduleDateChange(date) {
        setRescheduleDate(date);
        setRescheduleStartsAt('');
        setRescheduleSlots([]);
        if (!date || !reschedulingBooking) return;

        setLoadingSlots(true);
        try {
            const response = await fetch(`/dashboard/bookings/${reschedulingBooking.id}/reschedule-slots?date=${date}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            setRescheduleSlots(data.slots ?? []);
        } finally {
            setLoadingSlots(false);
        }
    }

    function submitReschedule() {
        if (!reschedulingBooking || !rescheduleStartsAt) return;
        router.put(`/dashboard/bookings/${reschedulingBooking.id}/reschedule`, { starts_at: rescheduleStartsAt }, {
            onSuccess: () => setReschedulingBooking(null),
        });
    }

    const hasFilters = Boolean(filters.status || filters.employee_id || filters.from);
    const groups = groupByDay(bookings);

    return (
        <DashboardLayout>
            {realtimeEnabled && <BookingsRealtime businessId={businessId} only={RELOAD_ONLY} />}

            <PageHeader
                title="Reservas"
                subtitle="Se actualizan solas cuando cambia algo."
                actions={(
                    <Button href="/dashboard/bookings/create" variant="primary">
                        <PlusIcon size={14} />
                        Nueva reserva
                    </Button>
                )}
            />

            <FilterBar
                filters={filters}
                employees={employees}
                hasFilters={hasFilters}
                total={bookings.length}
                onChange={(key, value) => applyFilters({ [key]: value })}
                onClear={clearFilters}
            />

            {bookings.length === 0 ? (
                <EmptyState
                    icon={CalendarIcon}
                    title={hasFilters ? 'Ninguna reserva coincide' : 'Todavía no hay reservas'}
                    description={hasFilters
                        ? 'Ningún turno coincide con los filtros elegidos. Probá con otra combinación.'
                        : 'Cuando se cargue una reserva, va a aparecer acá.'}
                    action={hasFilters ? (
                        <button type="button" onClick={clearFilters} className="text-[13px] font-medium underline">
                            Limpiar filtros
                        </button>
                    ) : (
                        <Link href="/dashboard/bookings/create" className="text-[13px] font-medium underline">
                            Nueva reserva
                        </Link>
                    )}
                />
            ) : (
                groups.map((group) => (
                    <div key={group.date_key} className="mb-6">
                        <DayHeading group={group} />
                        <TableShell>
                            {group.bookings.map((booking) => (
                                <BookingRow key={booking.id} booking={booking} onReschedule={openReschedule} />
                            ))}
                        </TableShell>
                    </div>
                ))
            )}

            <Modal open={reschedulingBooking !== null} onClose={closeReschedule} title="Reprogramar reserva">
                {reschedulingBooking && (
                    <div className="flex flex-col gap-4">
                        <div>
                            <label htmlFor="reschedule-date" className="mb-1.5 block text-[13px] font-medium">Nueva fecha</label>
                            <Input
                                id="reschedule-date"
                                type="date"
                                data-autofocus
                                value={rescheduleDate}
                                onChange={(event) => onRescheduleDateChange(event.target.value)}
                            />
                        </div>
                        <div>
                            <div className="mb-1.5 text-[13px] font-medium">Nuevo horario</div>
                            {!rescheduleDate ? (
                                <p className="text-[13px] leading-5 text-muted">Elegí una fecha para ver los horarios disponibles.</p>
                            ) : loadingSlots ? (
                                <p className="text-[13px] leading-5 text-muted">Cargando horarios…</p>
                            ) : (
                                <SlotPicker slots={rescheduleSlots} value={rescheduleStartsAt} onChange={setRescheduleStartsAt} />
                            )}
                            <InputError message={errors.starts_at} />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button variant="secondary" onClick={closeReschedule}>Cancelar</Button>
                            <Button variant="primary" onClick={submitReschedule} disabled={!rescheduleStartsAt}>Confirmar</Button>
                        </div>
                    </div>
                )}
            </Modal>

            <Toast message={toastMessage} onDismiss={() => setToastMessage(null)} />
        </DashboardLayout>
    );
}
