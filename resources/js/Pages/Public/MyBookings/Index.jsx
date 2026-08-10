import { router } from '@inertiajs/react';
import { useState } from 'react';

const STATUS_LABELS = {
    pending: 'Pendiente',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    completed: 'Completada',
    no_show: 'Ausencia',
};

export default function Index({ bookings }) {
    const [reschedulingId, setReschedulingId] = useState(null);
    const [rescheduleDate, setRescheduleDate] = useState('');
    const [rescheduleSlots, setRescheduleSlots] = useState([]);
    const [rescheduleStartsAt, setRescheduleStartsAt] = useState('');
    const [loadingSlots, setLoadingSlots] = useState(false);

    function cancel(booking) {
        if (confirm('¿Cancelar esta reserva?')) {
            router.post(`/mis-reservas/${booking.id}/cancel`);
        }
    }

    function startReschedule(booking) {
        setReschedulingId(booking.id);
        setRescheduleDate('');
        setRescheduleSlots([]);
        setRescheduleStartsAt('');
    }

    async function onRescheduleDateChange(booking, date) {
        setRescheduleDate(date);
        setRescheduleStartsAt('');
        setRescheduleSlots([]);
        if (!date) {
            return;
        }
        setLoadingSlots(true);
        try {
            const response = await fetch(`/mis-reservas/${booking.id}/reschedule-slots?date=${date}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            setRescheduleSlots(data.slots ?? []);
        } finally {
            setLoadingSlots(false);
        }
    }

    function submitReschedule(booking) {
        if (!rescheduleStartsAt) {
            return;
        }
        router.put(`/mis-reservas/${booking.id}/reschedule`, { starts_at: rescheduleStartsAt }, {
            onSuccess: () => setReschedulingId(null),
        });
    }

    return (
        <div className="min-h-screen bg-gray-50 p-8">
            <h1 className="mb-6 text-2xl font-bold">Mis reservas</h1>
            <ul className="space-y-4">
                {bookings.map((booking) => {
                    const cutoff = new Date(booking.starts_at);
                    cutoff.setHours(cutoff.getHours() - (booking.business?.cancellation_hours ?? 0));
                    const canAct = ['pending', 'confirmed'].includes(booking.status) && new Date() <= cutoff;

                    return (
                        <li key={booking.id} className="rounded-md border bg-white p-4">
                            <p className="font-semibold">{booking.business?.name} — {booking.service?.name}</p>
                            <p className="text-sm text-gray-500">{booking.employee?.name} · {new Date(booking.starts_at).toLocaleString()}</p>
                            <p className="text-sm text-gray-500">{STATUS_LABELS[booking.status] ?? booking.status}</p>
                            {canAct && (
                                <div className="mt-2 flex gap-4">
                                    <button onClick={() => startReschedule(booking)} className="text-sm underline">
                                        Reprogramar
                                    </button>
                                    <button onClick={() => cancel(booking)} className="text-sm text-red-600 underline">
                                        Cancelar
                                    </button>
                                </div>
                            )}
                            {reschedulingId === booking.id && (
                                <div className="mt-2 rounded-md border bg-gray-50 p-3">
                                    <label className="block text-xs font-medium text-gray-700">Nueva fecha</label>
                                    <input
                                        type="date"
                                        value={rescheduleDate}
                                        onChange={(e) => onRescheduleDateChange(booking, e.target.value)}
                                        className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                                    />
                                    <label className="mt-2 block text-xs font-medium text-gray-700">Nuevo horario</label>
                                    <select
                                        value={rescheduleStartsAt}
                                        onChange={(e) => setRescheduleStartsAt(e.target.value)}
                                        disabled={loadingSlots}
                                        className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm disabled:opacity-50"
                                    >
                                        <option value="">{loadingSlots ? 'Cargando…' : 'Elegir…'}</option>
                                        {rescheduleSlots.map((slot) => (
                                            <option key={slot.starts_at} value={slot.starts_at}>
                                                {new Date(slot.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                            </option>
                                        ))}
                                    </select>
                                    <div className="mt-2 flex gap-3">
                                        <button
                                            onClick={() => submitReschedule(booking)}
                                            disabled={!rescheduleStartsAt}
                                            className="rounded-md bg-gray-900 px-3 py-1 text-xs font-semibold text-white disabled:opacity-50"
                                        >
                                            Confirmar
                                        </button>
                                        <button onClick={() => setReschedulingId(null)} className="text-xs underline">Cancelar</button>
                                    </div>
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
