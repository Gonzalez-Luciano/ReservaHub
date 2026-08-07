import { Link, router, useForm } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

function EmployeeServices({ employee, services }) {
    const { data, setData, put, processing } = useForm({ service_ids: employee.service_ids });

    function toggle(id) {
        setData(
            'service_ids',
            data.service_ids.includes(id)
                ? data.service_ids.filter((serviceId) => serviceId !== id)
                : [...data.service_ids, id],
        );
    }

    function save(e) {
        e.preventDefault();
        put(`/dashboard/employees/${employee.id}/services`);
    }

    return (
        <form onSubmit={save} className="mt-2 flex flex-wrap items-center gap-3 text-xs text-gray-600">
            {services.map((service) => (
                <label key={service.id} className="flex items-center gap-1">
                    <input
                        type="checkbox"
                        checked={data.service_ids.includes(service.id)}
                        onChange={() => toggle(service.id)}
                    />
                    {service.name}
                </label>
            ))}
            <button type="submit" disabled={processing} className="underline disabled:opacity-50">
                Guardar servicios
            </button>
        </form>
    );
}

export default function Index({ employees, invitations, services }) {
    const { data, setData, post, processing, errors, reset } = useForm({ email: '', name: '' });

    function invite(e) {
        e.preventDefault();
        post('/dashboard/employees/invitations', { onSuccess: () => reset() });
    }

    function resend(invitation) {
        router.post(`/dashboard/employees/invitations/${invitation.id}/resend`);
    }

    function revoke(invitation) {
        if (confirm(`¿Revocar invitación a ${invitation.email}?`)) {
            router.delete(`/dashboard/employees/invitations/${invitation.id}`);
        }
    }

    return (
        <DashboardLayout>
            <div className="p-8">
                <h1 className="mb-6 text-2xl font-bold">Empleados</h1>

                <table className="mb-8 w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Nombre</th>
                            <th className="py-2">Email</th>
                            <th className="py-2">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        {employees.map((employee) => (
                            <tr key={employee.id} className="border-b align-top">
                                <td className="py-2">{employee.name}</td>
                                <td className="py-2">{employee.email}</td>
                                <td className="py-2">
                                    {employee.is_active ? 'Activo' : 'Inactivo'}
                                    {' · '}
                                    <Link href={`/dashboard/employees/${employee.id}/schedule`} className="underline">
                                        Horario
                                    </Link>
                                    <EmployeeServices employee={employee} services={services} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <h2 className="mb-4 text-lg font-semibold">Invitaciones pendientes</h2>
                <table className="mb-8 w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Email</th>
                            <th className="py-2">Vence</th>
                            <th className="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {invitations.map((invitation) => (
                            <tr key={invitation.id} className="border-b">
                                <td className="py-2">{invitation.email}</td>
                                <td className="py-2">{invitation.expires_at}</td>
                                <td className="py-2 text-right">
                                    <button onClick={() => resend(invitation)} className="mr-4 underline">
                                        Reenviar
                                    </button>
                                    <button onClick={() => revoke(invitation)} className="text-red-600 underline">
                                        Revocar
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <h2 className="mb-4 text-lg font-semibold">Invitar empleado</h2>
                <form onSubmit={invite} className="max-w-sm space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Nombre (opcional)</label>
                        <input
                            type="text"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Email</label>
                        <input
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.email} />
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Enviar invitación
                    </button>
                </form>
            </div>
        </DashboardLayout>
    );
}
