import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

const STATUS_LABELS = {
    pending: 'Pendiente',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    completed: 'Completada',
    no_show: 'Ausencia',
};

const CONFIRM_MESSAGES = {
    confirm: '¿Confirmar esta reserva?',
    complete: '¿Marcar esta reserva como completada?',
    'no-show': '¿Marcar esta reserva como ausencia?',
    cancel: '¿Cancelar esta reserva?',
};

export default function Index({ bookings }) {
    const { errors } = usePage().props;
    const [reschedulingId, setReschedulingId] = useState(null);
    const [rescheduleDate, setRescheduleDate] = useState('');
    const [rescheduleSlots, setRescheduleSlots] = useState([]);
    const [rescheduleStartsAt, setRescheduleStartsAt] = useState('');
    const [loadingSlots, setLoadingSlots] = useState(false);

    function act(booking, action) {
        const message = CONFIRM_MESSAGES[action];
        if (message && !confirm(message)) {
            return;
        }
        router.post(`/dashboard/bookings/${booking.id}/${action}`);
    }

    function startReschedule(booking) {
        setReschedulingId(booking.id);
        setRescheduleDate('');
        setRescheduleSlots([]);
        setRescheduleStartsAt('');
    }

    function cancelReschedule() {
        setReschedulingId(null);
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
            const response = await fetch(`/dashboard/bookings/${booking.id}/reschedule-slots?date=${date}`, {
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
        router.put(`/dashboard/bookings/${booking.id}/reschedule`, { starts_at: rescheduleStartsAt }, {
            onSuccess: () => setReschedulingId(null),
        });
    }

    return (
        <DashboardLayout>
            <div className="p-8">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Reservas</h1>
                    <Link href="/dashboard/bookings/create" className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">
                        Nueva reserva
                    </Link>
                </div>
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Cliente</th>
                            <th className="py-2">Empleado</th>
                            <th className="py-2">Servicio</th>
                            <th className="py-2">Horario</th>
                            <th className="py-2">Estado</th>
                            <th className="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {bookings.map((booking) => (
                            <tr key={booking.id} className="border-b align-top">
                                <td className="py-2">{booking.customer?.name}</td>
                                <td className="py-2">{booking.employee?.name}</td>
                                <td className="py-2">{booking.service?.name}</td>
                                <td className="py-2">{new Date(booking.starts_at).toLocaleString()}</td>
                                <td className="py-2">{STATUS_LABELS[booking.status] ?? booking.status}</td>
                                <td className="py-2 text-right">
                                    <Link href={`/dashboard/bookings/${booking.id}`} className="mr-4 underline">Ver</Link>
                                    {booking.status === 'pending' && (
                                        <button onClick={() => act(booking, 'confirm')} className="mr-4 underline">Confirmar</button>
                                    )}
                                    {booking.status === 'confirmed' && (
                                        <>
                                            <button onClick={() => act(booking, 'complete')} className="mr-4 underline">Completar</button>
                                            <button onClick={() => act(booking, 'no-show')} className="mr-4 underline">Ausencia</button>
                                        </>
                                    )}
                                    {['pending', 'confirmed'].includes(booking.status) && (
                                        <>
                                            <button onClick={() => startReschedule(booking)} className="mr-4 underline">Reprogramar</button>
                                            <button onClick={() => act(booking, 'cancel')} className="text-red-600 underline">Cancelar</button>
                                        </>
                                    )}
                                    {reschedulingId === booking.id && (
                                        <div className="mt-2 rounded-md border bg-gray-50 p-3 text-left">
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
                                            <InputError message={errors.starts_at} />
                                            <div className="mt-2 flex gap-3">
                                                <button
                                                    onClick={() => submitReschedule(booking)}
                                                    disabled={!rescheduleStartsAt}
                                                    className="rounded-md bg-gray-900 px-3 py-1 text-xs font-semibold text-white disabled:opacity-50"
                                                >
                                                    Confirmar
                                                </button>
                                                <button onClick={cancelReschedule} className="text-xs underline">Cancelar</button>
                                            </div>
                                        </div>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </DashboardLayout>
    );
}
