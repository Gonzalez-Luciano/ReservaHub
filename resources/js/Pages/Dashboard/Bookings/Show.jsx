import DashboardLayout from '../../../Components/DashboardLayout';

const STATUS_LABELS = {
    pending: 'Pendiente',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    completed: 'Completada',
    no_show: 'Ausencia',
};

export default function Show({ booking }) {
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
            </div>
        </DashboardLayout>
    );
}
