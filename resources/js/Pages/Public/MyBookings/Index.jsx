import { router } from '@inertiajs/react';

const STATUS_LABELS = {
    pending: 'Pendiente',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    completed: 'Completada',
    no_show: 'Ausencia',
};

export default function Index({ bookings }) {
    function cancel(booking) {
        if (confirm('¿Cancelar esta reserva?')) {
            router.post(`/mis-reservas/${booking.id}/cancel`);
        }
    }

    return (
        <div className="min-h-screen bg-gray-50 p-8">
            <h1 className="mb-6 text-2xl font-bold">Mis reservas</h1>
            <ul className="space-y-4">
                {bookings.map((booking) => {
                    const cutoff = new Date(booking.starts_at);
                    cutoff.setHours(cutoff.getHours() - (booking.business?.cancellation_hours ?? 0));
                    const canCancel = ['pending', 'confirmed'].includes(booking.status) && new Date() <= cutoff;

                    return (
                        <li key={booking.id} className="rounded-md border bg-white p-4">
                            <p className="font-semibold">{booking.business?.name} — {booking.service?.name}</p>
                            <p className="text-sm text-gray-500">{booking.employee?.name} · {new Date(booking.starts_at).toLocaleString()}</p>
                            <p className="text-sm text-gray-500">{STATUS_LABELS[booking.status] ?? booking.status}</p>
                            {canCancel && (
                                <button onClick={() => cancel(booking)} className="mt-2 text-sm text-red-600 underline">
                                    Cancelar
                                </button>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
