import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

export default function Form({ services, employees, slots }) {
    const { data, setData, post, processing, errors } = useForm({
        customer_email: '',
        service_id: '',
        employee_id: '',
        date: '',
        starts_at: '',
        notes: '',
    });
    const [loadingSlots, setLoadingSlots] = useState(false);

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

    function submit(e) {
        e.preventDefault();
        post('/dashboard/bookings');
    }

    return (
        <DashboardLayout>
            <div className="mx-auto max-w-lg p-8">
                <h1 className="mb-6 text-2xl font-bold">Nueva reserva</h1>
                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Email del cliente</label>
                        <input
                            type="email"
                            value={data.customer_email}
                            onChange={(e) => setData('customer_email', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.customer_email} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Servicio</label>
                        <select
                            value={data.service_id}
                            onChange={(e) => setData((d) => ({ ...d, service_id: e.target.value, employee_id: '', starts_at: '' }))}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        >
                            <option value="">Elegir…</option>
                            {services.map((service) => (
                                <option key={service.id} value={service.id}>{service.name}</option>
                            ))}
                        </select>
                        <InputError message={errors.service_id} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Empleado</label>
                        <select
                            value={data.employee_id}
                            onChange={(e) => setData((d) => ({ ...d, employee_id: e.target.value, starts_at: '' }))}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        >
                            <option value="">Elegir…</option>
                            {employees.map((employee) => (
                                <option key={employee.id} value={employee.id}>{employee.name}</option>
                            ))}
                        </select>
                        <InputError message={errors.employee_id} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Fecha</label>
                        <input
                            type="date"
                            value={data.date}
                            onChange={(e) => setData((d) => ({ ...d, date: e.target.value, starts_at: '' }))}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Horario</label>
                        <select
                            value={data.starts_at}
                            onChange={(e) => setData('starts_at', e.target.value)}
                            disabled={loadingSlots}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm disabled:opacity-50"
                        >
                            <option value="">{loadingSlots ? 'Cargando horarios…' : 'Elegir…'}</option>
                            {slots.map((slot) => (
                                <option key={slot.starts_at} value={slot.starts_at}>
                                    {new Date(slot.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                </option>
                            ))}
                        </select>
                        {loadingSlots && <p className="mt-1 text-sm text-gray-500">Buscando horarios disponibles…</p>}
                        <InputError message={errors.starts_at} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Notas internas (opcional)</label>
                        <textarea
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.notes} />
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Guardar
                    </button>
                </form>
            </div>
        </DashboardLayout>
    );
}
