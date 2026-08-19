import { router } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';

const STATUS_LABELS = {
    pending: 'Pendiente',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    completed: 'Completada',
    no_show: 'Ausencia',
};

export default function Show({ booking, payments }) {
    return (
        <DashboardLayout>
            <div className="mx-auto max-w-2xl p-8">
                <h1 className="mb-6 text-2xl font-bold">Reserva #{booking.id}</h1>
                <dl className="mb-8 space-y-2 text-sm">
                    <div><dt className="inline font-medium">Cliente: </dt><dd className="inline">{booking.customer?.name} ({booking.customer?.email})</dd></div>
                    <div><dt className="inline font-medium">Empleado: </dt><dd className="inline">{booking.employee?.name}</dd></div>
                    <div><dt className="inline font-medium">Servicio: </dt><dd className="inline">{booking.service?.name}</dd></div>
                    <div><dt className="inline font-medium">Horario: </dt><dd className="inline">{new Date(booking.starts_at).toLocaleString()} – {new Date(booking.ends_at).toLocaleString()}</dd></div>
                    <div><dt className="inline font-medium">Estado: </dt><dd className="inline">{STATUS_LABELS[booking.status] ?? booking.status}</dd></div>
                    {booking.notes && <div><dt className="inline font-medium">Notas: </dt><dd className="inline">{booking.notes}</dd></div>}
                </dl>
                <h2 className="mb-2 text-lg font-semibold">Historial</h2>
                <ul className="space-y-1 text-sm text-gray-600">
                    {booking.status_histories?.map((entry) => (
                        <li key={entry.id}>
                            {new Date(entry.created_at).toLocaleString()} — {STATUS_LABELS[entry.from_status] ?? 'nueva'} → {STATUS_LABELS[entry.to_status] ?? entry.to_status} ({entry.changed_by?.name ?? 'sistema'})
                            {entry.notes && ` — ${entry.notes}`}
                        </li>
                    ))}
                </ul>
                <section className="mt-6">
                    <h2 className="mb-2 text-lg font-semibold">Pagos de seña</h2>
                    {payments.length === 0 ? (
                        <p className="text-gray-600">Sin intentos de pago.</p>
                    ) : (
                        <ul className="space-y-1">
                            {payments.map((payment) => (
                                <li key={payment.id} className="text-sm">
                                    {payment.amount} {payment.currency} — {payment.status}
                                    {payment.application_outcome === 'booking_not_pending' && ' (cobrada sin aplicar)'}
                                </li>
                            ))}
                        </ul>
                    )}
                    {booking.status === 'pending' && booking.deposit_amount > 0 && !payments.some((p) => p.status === 'pending') && (
                        <button
                            type="button"
                            className="mt-2 rounded bg-blue-600 px-3 py-1 text-sm font-semibold text-white"
                            onClick={() => router.post(`/dashboard/bookings/${booking.id}/pagos`)}
                        >
                            Pagar seña
                        </button>
                    )}
                </section>
            </div>
        </DashboardLayout>
    );
}
