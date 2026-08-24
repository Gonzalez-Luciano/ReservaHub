import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';
import Button from '../../../Components/ui/Button';
import { Input } from '../../../Components/ui/Field';
import Modal from '../../../Components/ui/Modal';
import PageHeader from '../../../Components/ui/PageHeader';
import Surface from '../../../Components/ui/Surface';
import BookingStatusBadge from '../../../Components/domain/BookingStatusBadge';
import PaymentStatusBadge from '../../../Components/domain/PaymentStatusBadge';
import BookingActions from '../../../Components/domain/BookingActions';
import SlotPicker from '../../../Components/domain/SlotPicker';

const STATUS_LABELS = {
    pending: 'Pendiente',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    completed: 'Completada',
    no_show: 'Ausencia',
};

function formatMoney(amount) {
    const value = Number(amount);
    if (!Number.isFinite(value) || value <= 0) return null;
    return `$${value.toLocaleString('es-AR')}`;
}

function formatTime(iso) {
    return new Date(iso).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
}

function formatDateTime(iso) {
    return new Date(iso).toLocaleString('es-AR', { dateStyle: 'medium', timeStyle: 'short' });
}

export default function Show({ booking, payments }) {
    const { errors } = usePage().props;

    const [rescheduling, setRescheduling] = useState(false);
    const [rescheduleDate, setRescheduleDate] = useState('');
    const [rescheduleSlots, setRescheduleSlots] = useState([]);
    const [rescheduleStartsAt, setRescheduleStartsAt] = useState('');
    const [loadingSlots, setLoadingSlots] = useState(false);

    const cancelled = booking.status === 'cancelled';
    const deposit = formatMoney(booking.deposit_amount);
    const total = formatMoney(booking.price);
    const canPayDeposit = booking.status === 'pending' && booking.deposit_amount > 0 && !payments.some((payment) => payment.status === 'pending');

    function openReschedule() {
        setRescheduleDate('');
        setRescheduleSlots([]);
        setRescheduleStartsAt('');
        setRescheduling(true);
    }

    function closeReschedule() {
        setRescheduling(false);
    }

    async function onRescheduleDateChange(date) {
        setRescheduleDate(date);
        setRescheduleStartsAt('');
        setRescheduleSlots([]);
        if (!date) return;

        setLoadingSlots(true);
        try {
            const response = await fetch(`/dashboard/bookings/${booking.id}/reschedule-slots?date=${date}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            setRescheduleSlots(data.slots ?? []);
        } finally {
            setLoadingSlots(false);
        }
    }

    function submitReschedule() {
        if (!rescheduleStartsAt) return;
        router.put(`/dashboard/bookings/${booking.id}/reschedule`, { starts_at: rescheduleStartsAt }, {
            onSuccess: () => setRescheduling(false),
        });
    }

    return (
        <DashboardLayout>
            <PageHeader
                title={`Reserva #${booking.id}`}
                actions={<Button href="/dashboard/bookings" variant="secondary">Volver</Button>}
            />

            <div className="flex flex-col gap-4">
                {/* Estado y hora arriba (§8.6). La hora tachada es la misma
                    señal de "cancelada" que ya usan Index y DayRail. */}
                <Surface className="p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <span className={`tnum text-[22px] font-semibold tracking-[-0.01em] ${cancelled ? 'text-muted line-through' : ''}`}>
                                {formatTime(booking.starts_at)} – {formatTime(booking.ends_at)}
                            </span>
                            <div className="mt-0.5 text-[13px] text-muted">{formatDateTime(booking.starts_at)}</div>
                        </div>
                        <BookingStatusBadge status={booking.status} />
                    </div>
                </Surface>

                {/* Servicio, cliente, empleado */}
                <Surface className="p-5">
                    <dl className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <dt className="micro mb-1">Servicio</dt>
                            <dd className="text-[15px] font-medium">{booking.service?.name}</dd>
                        </div>
                        <div>
                            <dt className="micro mb-1">Cliente</dt>
                            <dd className="text-[15px] font-medium">{booking.customer?.name}</dd>
                            <dd className="text-[13px] text-muted">{booking.customer?.email}</dd>
                        </div>
                        <div>
                            <dt className="micro mb-1">Empleado</dt>
                            <dd className="text-[15px] font-medium">{booking.employee?.name}</dd>
                        </div>
                    </dl>
                    {booking.notes && (
                        <div className="mt-4 border-t border-border pt-4">
                            <dt className="micro mb-1">Notas</dt>
                            <dd className="text-[14px]">{booking.notes}</dd>
                        </div>
                    )}
                </Surface>

                {/* Seña y pagos. Nunca se exponen internals del proveedor
                    (external_id, snapshots, eventos de webhook): `payments`
                    ya llega recortado por PaymentResource. */}
                <Surface className="p-5">
                    <h2 className="mb-3 text-[15px] font-semibold">Seña y pagos</h2>
                    {deposit && (
                        <p className="mb-3 text-[13px] text-muted">
                            Seña {deposit}{total ? ` de ${total}` : ''}
                        </p>
                    )}
                    {payments.length === 0 ? (
                        <p className="text-[13px] text-muted">Sin intentos de pago.</p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {payments.map((payment) => (
                                <li key={payment.id} className="flex flex-wrap items-center gap-2 text-[13px]">
                                    <PaymentStatusBadge status={payment.status} />
                                    <span className="tnum font-medium">{payment.amount} {payment.currency}</span>
                                    {payment.application_outcome === 'booking_not_pending' && (
                                        <span className="text-muted">(cobrada sin aplicar)</span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    {canPayDeposit && (
                        <Button
                            variant="primary"
                            size="sm"
                            className="mt-3"
                            onClick={() => router.post(`/dashboard/bookings/${booking.id}/pagos`)}
                        >
                            Pagar seña
                        </Button>
                    )}
                </Surface>

                {/* Acciones de ciclo de vida: hoy solo existían en Index,
                    mismo BookingActions y las mismas Policies. */}
                <Surface className="p-5">
                    <h2 className="mb-3 text-[15px] font-semibold">Acciones</h2>
                    <div className="flex flex-wrap gap-2">
                        <BookingActions booking={booking} onReschedule={openReschedule} />
                    </div>
                </Surface>

                {/* Historial: si no hay, la sección directamente no se renderiza. */}
                {booking.status_histories?.length > 0 && (
                    <Surface className="p-5">
                        <h2 className="mb-3 text-[15px] font-semibold">Historial</h2>
                        <ul className="flex flex-col gap-2 text-[13px] text-muted">
                            {booking.status_histories.map((entry) => (
                                <li key={entry.id}>
                                    <span className="tnum">{formatDateTime(entry.created_at)}</span>
                                    {' — '}
                                    {STATUS_LABELS[entry.from_status] ?? 'nueva'} → {STATUS_LABELS[entry.to_status] ?? entry.to_status}
                                    {' '}({entry.changed_by?.name ?? 'sistema'})
                                    {entry.notes && ` — ${entry.notes}`}
                                </li>
                            ))}
                        </ul>
                    </Surface>
                )}
            </div>

            <Modal open={rescheduling} onClose={closeReschedule} title="Reprogramar reserva">
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
            </Modal>
        </DashboardLayout>
    );
}
