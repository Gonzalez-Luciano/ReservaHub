import { Link, router, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import PageHeader from '../../../Components/ui/PageHeader';
import Button from '../../../Components/ui/Button';
import TableShell from '../../../Components/ui/TableShell';
import StatusBadge from '../../../Components/ui/StatusBadge';
import { CheckCircleIcon, SlashCircleIcon, PlusIcon } from '../../../Components/ui/icons';

const MANAGER_ROLES = ['owner', 'admin'];

export default function Index({ services }) {
    const { auth } = usePage().props;
    const isManager = MANAGER_ROLES.includes(auth?.user?.role);
    const currency = auth?.business?.currency || 'USD';

    function destroy(service) {
        if (confirm(`¿Eliminar "${service.name}"?`)) {
            router.delete(`/dashboard/services/${service.id}`);
        }
    }

    function formatCurrency(value) {
        return new Intl.NumberFormat('es-AR', {
            style: 'currency',
            currency: currency,
        }).format(value);
    }

    function truncateDescription(desc, maxLength = 50) {
        if (!desc) return '';
        return desc.length > maxLength ? desc.substring(0, maxLength) + '...' : desc;
    }

    return (
        <DashboardLayout>
            <div className="space-y-6">
                <PageHeader
                    title="Servicios"
                    actions={isManager && (
                        <Link href="/dashboard/services/create">
                            <Button variant="primary" size="sm">
                                <PlusIcon size={14} />
                                Nuevo servicio
                            </Button>
                        </Link>
                    )}
                />

                <TableShell>
                    <div className="hidden md:contents">
                        <div className="micro grid gap-4 border-b border-border bg-chrome px-4 py-3 text-muted sm:grid-cols-[1fr_80px_100px_80px_100px_80px_80px_80px]">
                            <div>Nombre</div>
                            <div>Duración</div>
                            <div>Buffer</div>
                            <div>Precio</div>
                            <div>Seña</div>
                            <div>Estado</div>
                            {isManager && <div className="text-right">Acciones</div>}
                        </div>
                    </div>

                    {services.map((service) => (
                        <div key={service.id} className="flex flex-col gap-3 px-4 py-4 sm:grid sm:gap-4 sm:grid-cols-[1fr_80px_100px_80px_100px_80px_80px_80px]">
                            <div className="space-y-2">
                                <div className="font-medium">{service.name}</div>
                                <div className="text-xs text-muted">{truncateDescription(service.description)}</div>
                            </div>
                            <div className="text-sm tnum">{service.duration_minutes} min</div>
                            <div className="text-sm tnum">{service.buffer_minutes} min</div>
                            <div className="text-sm tnum">{formatCurrency(service.price)}</div>
                            <div className="text-sm tnum">
                                {service.deposit_amount ? formatCurrency(service.deposit_amount) : '—'}
                            </div>
                            <div className="flex items-center">
                                <StatusBadge
                                    tone={service.is_active ? 'confirmed' : 'neutral'}
                                    icon={service.is_active ? CheckCircleIcon : SlashCircleIcon}
                                    label={service.is_active ? 'Activo' : 'Inactivo'}
                                />
                            </div>
                            {isManager && (
                                <div className="flex items-center justify-end gap-2 sm:text-sm">
                                    <Link href={`/dashboard/services/${service.id}/edit`} className="text-fg underline hover:no-underline">
                                        Editar
                                    </Link>
                                    <button
                                        onClick={() => destroy(service)}
                                        className="text-noshow-fg underline hover:no-underline"
                                    >
                                        Eliminar
                                    </button>
                                </div>
                            )}
                        </div>
                    ))}
                </TableShell>
            </div>
        </DashboardLayout>
    );
}
