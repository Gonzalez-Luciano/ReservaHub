import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';
import PageHeader from '../../../Components/ui/PageHeader';
import Button from '../../../Components/ui/Button';
import Surface from '../../../Components/ui/Surface';
import TableShell from '../../../Components/ui/TableShell';
import EmptyState from '../../../Components/ui/EmptyState';
import StatusBadge from '../../../Components/ui/StatusBadge';
import Alert from '../../../Components/ui/Alert';
import Modal from '../../../Components/ui/Modal';
import ConfirmDialog from '../../../Components/ui/ConfirmDialog';
import { FormField, Input } from '../../../Components/ui/Field';
import { CheckCircleIcon, MailIcon, PeopleIcon, SlashCircleIcon } from '../../../Components/ui/icons';

const MANAGER_ROLES = ['owner', 'admin'];

function EmployeeServicesForm({ employee, services, onSaved }) {
    const { data, setData, put, processing, errors } = useForm({ service_ids: employee.service_ids });

    function toggle(id) {
        setData(
            'service_ids',
            data.service_ids.includes(id)
                ? data.service_ids.filter((serviceId) => serviceId !== id)
                : [...data.service_ids, id],
        );
    }

    function save() {
        put(`/dashboard/employees/${employee.id}/services`, { preserveScroll: true, onSuccess: onSaved });
    }

    return (
        <div className="space-y-4">
            {services.length === 0 ? (
                <p className="text-[13px] text-muted">Todavía no hay servicios activos para asignar.</p>
            ) : (
                <div className="space-y-2.5">
                    {services.map((service) => (
                        <label key={service.id} className="flex items-center gap-2.5 text-[14px]">
                            <input
                                type="checkbox"
                                checked={data.service_ids.includes(service.id)}
                                onChange={() => toggle(service.id)}
                                className="h-4 w-4 rounded border border-border"
                            />
                            {service.name}
                        </label>
                    ))}
                </div>
            )}
            <InputError message={errors.service_ids} />
            <div className="flex justify-end gap-2 border-t border-border pt-4">
                <Button variant="secondary" onClick={onSaved}>Cancelar</Button>
                <Button variant="primary" onClick={save} disabled={processing}>Guardar servicios</Button>
            </div>
        </div>
    );
}

function EmployeeRow({ employee, isManager, onEditServices, onToggleStatus }) {
    return (
        <div className="flex flex-col gap-3 px-4 py-4 sm:grid sm:grid-cols-[1fr_1fr_100px_auto] sm:items-center sm:gap-4">
            <div className="font-medium">{employee.name}</div>
            <div className="truncate text-[13px] text-fg-body">{employee.email}</div>
            <div>
                <StatusBadge
                    tone={employee.is_active ? 'confirmed' : 'neutral'}
                    icon={employee.is_active ? CheckCircleIcon : SlashCircleIcon}
                    label={employee.is_active ? 'Activo' : 'Inactivo'}
                />
            </div>
            <div className="flex flex-wrap items-center justify-start gap-3 sm:justify-end">
                <Link href={`/dashboard/employees/${employee.id}/schedule`} className="text-[13px] underline hover:no-underline">
                    Horario
                </Link>
                <button type="button" onClick={() => onEditServices(employee)} className="text-[13px] underline hover:no-underline">
                    Servicios ({employee.service_ids.length})
                </button>
                {isManager && (
                    <button
                        type="button"
                        onClick={() => onToggleStatus(employee)}
                        className={`text-[13px] underline hover:no-underline ${employee.is_active ? 'text-noshow-fg' : ''}`}
                    >
                        {employee.is_active ? 'Desactivar' : 'Activar'}
                    </button>
                )}
            </div>
        </div>
    );
}

function InvitationRow({ invitation }) {
    function resend() {
        router.post(`/dashboard/employees/invitations/${invitation.id}/resend`, {}, { preserveScroll: true });
    }

    function revoke() {
        if (confirm(`¿Revocar invitación a ${invitation.email}?`)) {
            router.delete(`/dashboard/employees/invitations/${invitation.id}`, { preserveScroll: true });
        }
    }

    return (
        <div className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
            <div className="min-w-0">
                <div className="truncate text-[14px] font-medium">{invitation.email}</div>
                {invitation.name && <div className="truncate text-[12px] text-muted">{invitation.name}</div>}
            </div>
            <div className="flex items-center gap-4">
                <span className="tnum whitespace-nowrap text-[12px] text-muted">Vence {invitation.expires_at_display}</span>
                <button type="button" onClick={resend} className="text-[13px] underline hover:no-underline">Reenviar</button>
                <button type="button" onClick={revoke} className="text-[13px] text-noshow-fg underline hover:no-underline">Revocar</button>
            </div>
        </div>
    );
}

function InviteEmployeeForm() {
    const { data, setData, post, processing, errors, reset } = useForm({ email: '', name: '' });

    function submit(e) {
        e.preventDefault();
        post('/dashboard/employees/invitations', { preserveScroll: true, onSuccess: () => reset() });
    }

    return (
        <form onSubmit={submit} className="grid gap-4 p-5 sm:grid-cols-2">
            <FormField id="name" label="Nombre (opcional)" error={errors.name}>
                {(props) => (
                    <Input {...props} type="text" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                )}
            </FormField>
            <FormField id="email" label="Email" error={errors.email}>
                {(props) => (
                    <Input {...props} type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                )}
            </FormField>
            <div className="sm:col-span-2">
                <Button type="submit" variant="primary" disabled={processing}>
                    <MailIcon size={14} />
                    Enviar invitación
                </Button>
            </div>
        </form>
    );
}

export default function Index({ employees, invitations, services }) {
    const { auth, status, future_bookings_count: futureBookingsCount } = usePage().props;
    const isManager = MANAGER_ROLES.includes(auth?.user?.role);

    const [serviceModalEmployee, setServiceModalEmployee] = useState(null);
    const [statusTarget, setStatusTarget] = useState(null);

    function confirmToggleStatus() {
        if (!statusTarget) return;
        router.put(`/dashboard/users/${statusTarget.id}/status`, { is_active: !statusTarget.is_active }, { preserveScroll: true });
        setStatusTarget(null);
    }

    return (
        <DashboardLayout>
            <div className="space-y-8">
                <PageHeader title="Personal" subtitle="Empleados, horarios e invitaciones." />

                {status && (
                    <div className="flex items-center gap-2 rounded-md border border-border bg-confirmed-block px-4 py-3 text-[13px] font-medium text-confirmed-fg">
                        <CheckCircleIcon size={15} />
                        {status}
                    </div>
                )}

                {futureBookingsCount > 0 && (
                    <Alert tone="warning" title="Reservas futuras a su nombre">
                        Ese usuario tiene {futureBookingsCount} reserva(s) futura(s). No se cancelaron: reasignalas
                        o cancelalas desde Reservas.
                    </Alert>
                )}

                <section className="space-y-3">
                    <h2 className="text-[15px] font-semibold">Empleados</h2>
                    {employees.length === 0 ? (
                        <EmptyState icon={PeopleIcon} title="Todavía no hay empleados" description="Invitá a alguien más abajo para empezar a asignarle horarios y servicios." />
                    ) : (
                        <TableShell>
                            {employees.map((employee) => (
                                <EmployeeRow
                                    key={employee.id}
                                    employee={employee}
                                    isManager={isManager}
                                    onEditServices={setServiceModalEmployee}
                                    onToggleStatus={setStatusTarget}
                                />
                            ))}
                        </TableShell>
                    )}
                </section>

                <section className="space-y-3">
                    <h2 className="text-[15px] font-semibold">Invitaciones pendientes</h2>
                    {invitations.length === 0 ? (
                        <EmptyState icon={MailIcon} title="Sin invitaciones pendientes" description="Las invitaciones que envíes van a aparecer acá hasta que se acepten." />
                    ) : (
                        <TableShell>
                            {invitations.map((invitation) => (
                                <InvitationRow key={invitation.id} invitation={invitation} />
                            ))}
                        </TableShell>
                    )}
                </section>

                <section className="space-y-3">
                    <h2 className="text-[15px] font-semibold">Invitar empleado</h2>
                    <Surface>
                        <InviteEmployeeForm />
                    </Surface>
                </section>
            </div>

            <Modal
                open={serviceModalEmployee !== null}
                onClose={() => setServiceModalEmployee(null)}
                title={serviceModalEmployee ? `Servicios de ${serviceModalEmployee.name}` : 'Servicios'}
            >
                {serviceModalEmployee && (
                    <EmployeeServicesForm
                        key={serviceModalEmployee.id}
                        employee={serviceModalEmployee}
                        services={services}
                        onSaved={() => setServiceModalEmployee(null)}
                    />
                )}
            </Modal>

            <ConfirmDialog
                open={statusTarget !== null}
                onCancel={() => setStatusTarget(null)}
                onConfirm={confirmToggleStatus}
                title={statusTarget?.is_active ? 'Desactivar empleado' : 'Activar empleado'}
                description={`¿Querés ${statusTarget?.is_active ? 'desactivar' : 'activar'} a ${statusTarget?.name}?`}
                confirmLabel={statusTarget?.is_active ? 'Desactivar' : 'Activar'}
                tone={statusTarget?.is_active ? 'danger' : 'primary'}
            />
        </DashboardLayout>
    );
}
