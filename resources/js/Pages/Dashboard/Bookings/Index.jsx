import { Link, router } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';

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
    function act(booking, action) {
        const message = CONFIRM_MESSAGES[action];
        if (message && !confirm(message)) {
            return;
        }
        router.post(`/dashboard/bookings/${booking.id}/${action}`);
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
                            <tr key={booking.id} className="border-b">
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
                                        <button onClick={() => act(booking, 'cancel')} className="text-red-600 underline">Cancelar</button>
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
