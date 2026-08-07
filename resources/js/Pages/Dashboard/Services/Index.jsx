import { Link, router, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';

const MANAGER_ROLES = ['owner', 'admin'];

export default function Index({ services }) {
    const { auth } = usePage().props;
    const isManager = MANAGER_ROLES.includes(auth?.user?.role);

    function destroy(service) {
        if (confirm(`¿Eliminar "${service.name}"?`)) {
            router.delete(`/dashboard/services/${service.id}`);
        }
    }

    return (
        <DashboardLayout>
            <div className="p-8">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Servicios</h1>
                    {isManager && (
                        <Link
                            href="/dashboard/services/create"
                            className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                        >
                            Nuevo servicio
                        </Link>
                    )}
                </div>
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Nombre</th>
                            <th className="py-2">Duración</th>
                            <th className="py-2">Precio</th>
                            {isManager && <th className="py-2"></th>}
                        </tr>
                    </thead>
                    <tbody>
                        {services.map((service) => (
                            <tr key={service.id} className="border-b">
                                <td className="py-2">{service.name}</td>
                                <td className="py-2">{service.duration_minutes} min</td>
                                <td className="py-2">${service.price}</td>
                                {isManager && (
                                    <td className="py-2 text-right">
                                        <Link href={`/dashboard/services/${service.id}/edit`} className="mr-4 underline">
                                            Editar
                                        </Link>
                                        <button onClick={() => destroy(service)} className="text-red-600 underline">
                                            Eliminar
                                        </button>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </DashboardLayout>
    );
}
