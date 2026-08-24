import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '../../../Components/InputError';
import PublicLayout from '../../../Components/PublicLayout';
import Button from '../../../Components/ui/Button';
import ConfirmDialog from '../../../Components/ui/ConfirmDialog';
import EmptyState from '../../../Components/ui/EmptyState';
import { Input } from '../../../Components/ui/Field';
import Modal from '../../../Components/ui/Modal';
import PageHeader from '../../../Components/ui/PageHeader';
import Surface from '../../../Components/ui/Surface';
import BookingStatusBadge from '../../../Components/domain/BookingStatusBadge';
import PaymentStatusBadge from '../../../Components/domain/PaymentStatusBadge';
import SlotPicker from '../../../Components/domain/SlotPicker';
import { CalendarIcon } from '../../../Components/ui/icons';

const SECTIONS = [
    { key: 'upcoming', title: 'Próximas', empty: null },
    { key: 'pendingDeposit', title: 'Pendientes de seña', empty: null },
    { key: 'past', title: 'Pasadas', empty: null },
    { key: 'cancelled', title: 'Canceladas', empty: null },
];

function formatMoney(value, currency) {
    return new Intl.NumberFormat('es-AR', { style: 'currency', currency }).format(Number(value ?? 0));
}

function formatDateTime(iso) {
    return new Date(iso).toLocaleString('es-AR', { dateStyle: 'medium', timeStyle: 'short' });
}

// Una reserva pendiente con seña sin resolver (sin intento de pago, o con uno
// vivo en estado `pending`) va a "Pendientes de seña" en lugar de "Próximas":
// necesita una acción del cliente antes de poder confiar en el turno.
function needsDepositAction(booking) {
    if (booking.status !== 'pending' || !(Number(booking.deposit_amount) > 0)) {
        return false;
    }
    return !booking.payment || booking.payment.status === 'pending';
}

function groupBookings(bookings) {
    const groups = { upcoming: [], pendingDeposit: [], past: [], cancelled: [] };
    const now = new Date();

    for (const booking of bookings) {
        if (booking.status === 'cancelled') {
            groups.cancelled.push(booking);
        } else if (needsDepositAction(booking)) {
            groups.pendingDeposit.push(booking);
        } else if (booking.status === 'completed' || booking.status === 'no_show' || new Date(booking.starts_at) < now) {
            groups.past.push(booking);
        } else {
            groups.upcoming.push(booking);
        }
    }

    return groups;
}

function BookingCard({ booking, onReschedule, onCancel }) {
    const deposit = Number(booking.deposit_amount) > 0 ? booking.deposit_amount : null;
    const currency = booking.business?.currency;

    return (
        <Surface className="p-4 sm:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="text-[15px] font-semibold leading-5">{booking.service?.name}</div>
                    <div className="mt-0.5 text-[13px] text-muted">{booking.business?.name}</div>
                </div>
                <BookingStatusBadge status={booking.status} />
            </div>

            <div className="tnum mt-3 text-[13px] text-fg-body">
                {formatDateTime(booking.starts_at)} · {booking.employee?.name}
            </div>

            {deposit && (
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    {booking.payment ? (
                        <PaymentStatusBadge status={booking.payment.status} />
                    ) : (
                        <span className="text-[13px] text-muted">Seña sin pagar</span>
                    )}
                    <span className="tnum text-[13px] text-fg-body">
                        {formatMoney(deposit, currency)}{Number(booking.price) > 0 && ` de ${formatMoney(booking.price, currency)}`}
                    </span>
                </div>
            )}

            <div className="mt-4 flex flex-wrap gap-2">
                {booking.status === 'pending' && deposit && (!booking.payment || booking.payment.status !== 'pending') && (
                    <Button
                        size="md"
                        variant="primary"
                        className="h-11 w-full sm:h-[34px] sm:w-auto"
                        onClick={() => router.post(`/mis-reservas/${booking.id}/pagos`)}
                    >
                        Pagar seña
                    </Button>
                )}
                {booking.payment?.status === 'pending' && booking.payment.checkout_url && (
                    <Button
                        size="md"
                        variant="primary"
                        className="h-11 w-full sm:h-[34px] sm:w-auto"
                        href={booking.payment.checkout_url}
                    >
                        Continuar el pago
                    </Button>
                )}
                {booking.can_reschedule && (
                    <Button
                        size="md"
                        variant="secondary"
                        className="h-11 w-full sm:h-[34px] sm:w-auto"
                        onClick={() => onReschedule(booking)}
                    >
                        Reprogramar
                    </Button>
                )}
                {booking.can_cancel && (
                    <Button
                        size="md"
                        variant="danger"
                        className="h-11 w-full sm:h-[34px] sm:w-auto"
                        onClick={() => onCancel(booking)}
                    >
                        Cancelar
                    </Button>
                )}
            </div>
        </Surface>
    );
}

export default function Index({ bookings }) {
    const { errors } = usePage().props;

    const [reschedulingBooking, setReschedulingBooking] = useState(null);
    const [rescheduleDate, setRescheduleDate] = useState('');
    const [rescheduleSlots, setRescheduleSlots] = useState([]);
    const [rescheduleStartsAt, setRescheduleStartsAt] = useState('');
    const [loadingSlots, setLoadingSlots] = useState(false);
    const [cancellingBooking, setCancellingBooking] = useState(null);

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
            const response = await fetch(`/mis-reservas/${reschedulingBooking.id}/reschedule-slots?date=${date}`, {
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
        router.put(`/mis-reservas/${reschedulingBooking.id}/reschedule`, { starts_at: rescheduleStartsAt }, {
            onSuccess: () => setReschedulingBooking(null),
        });
    }

    function confirmCancel() {
        if (!cancellingBooking) return;
        router.post(`/mis-reservas/${cancellingBooking.id}/cancel`, {}, {
            onFinish: () => setCancellingBooking(null),
        });
    }

    const groups = groupBookings(bookings);
    const isEmpty = bookings.length === 0;

    return (
        <PublicLayout>
            <div className="mx-auto max-w-[720px] px-6 py-10 lg:px-10 lg:py-14">
                <PageHeader title="Mis reservas" subtitle="Todos tus turnos, en cualquier negocio de la demo." />

                {isEmpty ? (
                    <EmptyState
                        icon={CalendarIcon}
                        title="Todavía no reservaste nada"
                        description="Elegí un negocio y sacá tu primer turno."
                        action={<Link href="/negocios" className="text-[13px] font-medium underline">Ver negocios</Link>}
                    />
                ) : (
                    SECTIONS.map(({ key, title }) => (
                        groups[key].length > 0 && (
                            <div key={key} className="mb-8">
                                <div className="mb-2 flex items-center gap-2.5">
                                    <span className="micro">{title}</span>
                                    <div className="h-px flex-grow bg-border" aria-hidden="true" />
                                </div>
                                <div className="flex flex-col gap-3">
                                    {groups[key].map((booking) => (
                                        <BookingCard
                                            key={booking.id}
                                            booking={booking}
                                            onReschedule={openReschedule}
                                            onCancel={setCancellingBooking}
                                        />
                                    ))}
                                </div>
                            </div>
                        )
                    ))
                )}
            </div>

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

            <ConfirmDialog
                open={cancellingBooking !== null}
                onCancel={() => setCancellingBooking(null)}
                onConfirm={confirmCancel}
                title="Cancelar la reserva"
                description="La reserva pasa a cancelada y el horario vuelve a quedar libre. No se puede deshacer."
                confirmLabel="Cancelar la reserva"
            />
        </PublicLayout>
    );
}
