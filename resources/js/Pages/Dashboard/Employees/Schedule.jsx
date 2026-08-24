import { router, useForm } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import PageHeader from '../../../Components/ui/PageHeader';
import Button from '../../../Components/ui/Button';
import Surface from '../../../Components/ui/Surface';
import TableShell from '../../../Components/ui/TableShell';
import EmptyState from '../../../Components/ui/EmptyState';
import { FormField, Input } from '../../../Components/ui/Field';
import ScheduleEditor from '../../../Components/domain/ScheduleEditor';
import { HolidayIcon, PlusIcon } from '../../../Components/ui/icons';

function TimeOffRow({ timeOff }) {
    function remove() {
        if (confirm('¿Eliminar esta licencia?')) {
            router.delete(`/dashboard/time-offs/${timeOff.id}`, { preserveScroll: true });
        }
    }

    return (
        <div className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
            <div className="text-[13px]">
                <span className="tnum font-medium">{timeOff.starts_at_display}</span>
                <span className="mx-1.5 text-muted">→</span>
                <span className="tnum font-medium">{timeOff.ends_at_display}</span>
                {timeOff.reason && <span className="ml-2 text-muted">· {timeOff.reason}</span>}
            </div>
            <button type="button" onClick={remove} className="self-start text-[13px] text-noshow-fg underline hover:no-underline sm:self-auto">
                Eliminar
            </button>
        </div>
    );
}

function AddTimeOffForm({ employeeId }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        starts_at: '',
        ends_at: '',
        reason: '',
    });

    function submit(e) {
        e.preventDefault();
        post(`/dashboard/employees/${employeeId}/time-offs`, { preserveScroll: true, onSuccess: () => reset() });
    }

    return (
        <form onSubmit={submit} className="grid gap-4 p-5 sm:grid-cols-3">
            <FormField id="starts_at" label="Desde" error={errors.starts_at}>
                {(props) => (
                    <Input
                        {...props}
                        type="datetime-local"
                        value={data.starts_at}
                        onChange={(e) => setData('starts_at', e.target.value)}
                    />
                )}
            </FormField>
            <FormField id="ends_at" label="Hasta" error={errors.ends_at}>
                {(props) => (
                    <Input
                        {...props}
                        type="datetime-local"
                        value={data.ends_at}
                        onChange={(e) => setData('ends_at', e.target.value)}
                    />
                )}
            </FormField>
            <FormField id="reason" label="Motivo (opcional)" error={errors.reason}>
                {(props) => (
                    <Input
                        {...props}
                        type="text"
                        value={data.reason}
                        onChange={(e) => setData('reason', e.target.value)}
                    />
                )}
            </FormField>
            <div className="sm:col-span-3">
                <Button type="submit" variant="primary" disabled={processing}>
                    <PlusIcon size={14} />
                    Agregar licencia
                </Button>
            </div>
        </form>
    );
}

export default function Schedule({ employee, schedules, timeOffs }) {
    return (
        <DashboardLayout>
            <div className="space-y-8">
                <PageHeader
                    title={`Horario de ${employee.name}`}
                    subtitle={employee.email}
                    actions={<Button href="/dashboard/employees" variant="secondary">Volver a empleados</Button>}
                />

                <ScheduleEditor employeeId={employee.id} schedules={schedules} />

                <div className="space-y-3">
                    <h2 className="text-[15px] font-semibold">Licencias</h2>

                    {timeOffs.length === 0 ? (
                        <EmptyState icon={HolidayIcon} title="Sin licencias cargadas" description="Las ausencias programadas de este empleado van a aparecer acá." />
                    ) : (
                        <TableShell>
                            {timeOffs.map((timeOff) => (
                                <TimeOffRow key={timeOff.id} timeOff={timeOff} />
                            ))}
                        </TableShell>
                    )}

                    <Surface>
                        <AddTimeOffForm employeeId={employee.id} />
                    </Surface>
                </div>
            </div>
        </DashboardLayout>
    );
}
