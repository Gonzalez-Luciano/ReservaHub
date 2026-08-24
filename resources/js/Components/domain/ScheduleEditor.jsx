import { router, useForm } from '@inertiajs/react';
import Button from '../ui/Button';
import TableShell from '../ui/TableShell';
import InputError from '../InputError';
import { Input } from '../ui/Field';
import { PlusIcon } from '../ui/icons';

// Mismo orden que `App\Enums\DayOfWeek` (0 = domingo): los horarios llegan del
// servidor con `day_of_week` como entero (Eloquent serializa el enum backed a
// su valor escalar), así que este array indexa directo por ese valor.
const DAYS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

// Las columnas `time` de Postgres llegan como "HH:MM:SS"; los segundos no
// aportan nada a la vista semanal.
function trimSeconds(time) {
    return typeof time === 'string' ? time.slice(0, 5) : time;
}

function ScheduleBreakRow({ scheduleBreak }) {
    function remove() {
        if (confirm('¿Quitar esta pausa?')) {
            router.delete(`/dashboard/schedule-breaks/${scheduleBreak.id}`, { preserveScroll: true });
        }
    }

    return (
        <div className="flex items-center justify-between gap-3 text-[13px]">
            <span className="tnum text-fg-body">
                {trimSeconds(scheduleBreak.start_time)} – {trimSeconds(scheduleBreak.end_time)}
            </span>
            <button type="button" onClick={remove} className="text-noshow-fg underline hover:no-underline">
                Quitar
            </button>
        </div>
    );
}

function AddBreakForm({ schedule }) {
    const { data, setData, post, processing, errors, reset } = useForm({ start_time: '', end_time: '' });

    function submit(e) {
        e.preventDefault();
        post(`/dashboard/schedules/${schedule.id}/breaks`, { preserveScroll: true, onSuccess: () => reset() });
    }

    return (
        <form onSubmit={submit} className="mt-2 flex flex-wrap items-end gap-2">
            <div className="w-32">
                <Input type="time" value={data.start_time} onChange={(e) => setData('start_time', e.target.value)} aria-label="Inicio de la pausa" />
            </div>
            <div className="w-32">
                <Input type="time" value={data.end_time} onChange={(e) => setData('end_time', e.target.value)} aria-label="Fin de la pausa" />
            </div>
            <Button type="submit" size="sm" variant="secondary" disabled={processing}>Agregar pausa</Button>
            <InputError message={errors.start_time} />
            <InputError message={errors.end_time} />
        </form>
    );
}

function AddScheduleForm({ employeeId, dayOfWeek }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        day_of_week: dayOfWeek,
        start_time: '09:00',
        end_time: '18:00',
    });

    function submit(e) {
        e.preventDefault();
        post(`/dashboard/employees/${employeeId}/schedule`, {
            preserveScroll: true,
            onSuccess: () => reset('start_time', 'end_time'),
        });
    }

    return (
        <form onSubmit={submit} className="mt-2 flex flex-wrap items-end gap-2">
            <div className="w-32">
                <Input type="time" value={data.start_time} onChange={(e) => setData('start_time', e.target.value)} aria-label="Hora de inicio" />
            </div>
            <div className="w-32">
                <Input type="time" value={data.end_time} onChange={(e) => setData('end_time', e.target.value)} aria-label="Hora de fin" />
            </div>
            <Button type="submit" size="sm" variant="secondary" disabled={processing}>
                <PlusIcon size={13} />
                Agregar franja
            </Button>
            <InputError message={errors.start_time} />
            <InputError message={errors.end_time} />
        </form>
    );
}

function DayRow({ dayIndex, schedule, employeeId }) {
    function removeSchedule() {
        if (confirm('¿Eliminar este horario? También se eliminan sus pausas.')) {
            router.delete(`/dashboard/schedules/${schedule.id}`, { preserveScroll: true });
        }
    }

    return (
        <div className="flex flex-col gap-2 px-4 py-4 sm:flex-row sm:gap-6">
            <div className="w-28 shrink-0 pt-0.5 text-[13px] font-medium">{DAYS[dayIndex]}</div>

            <div className="min-w-0 flex-1">
                {schedule ? (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="tnum text-[15px] font-medium">
                                {trimSeconds(schedule.start_time)} – {trimSeconds(schedule.end_time)}
                            </span>
                            <button type="button" onClick={removeSchedule} className="text-[13px] text-noshow-fg underline hover:no-underline">
                                Eliminar horario
                            </button>
                        </div>

                        {schedule.breaks.length > 0 && (
                            <div className="mt-2 space-y-1.5 border-l-2 border-border pl-3">
                                {schedule.breaks.map((scheduleBreak) => (
                                    <ScheduleBreakRow key={scheduleBreak.id} scheduleBreak={scheduleBreak} />
                                ))}
                            </div>
                        )}

                        <AddBreakForm schedule={schedule} />
                    </>
                ) : (
                    <>
                        <p className="text-[13px] text-muted">Sin horario asignado</p>
                        <AddScheduleForm employeeId={employeeId} dayOfWeek={dayIndex} />
                    </>
                )}
            </div>
        </div>
    );
}

/**
 * Vista semanal del horario de un empleado: siete filas de día (domingo a
 * sábado, igual criterio de orden que `App\Enums\DayOfWeek`), cada una con su
 * franja y sus pausas anidadas. Reemplaza las cuatro tablas y tres
 * formularios sueltos de la versión anterior de `Employees/Schedule`.
 */
export default function ScheduleEditor({ employeeId, schedules }) {
    const byDay = new Map(schedules.map((schedule) => [schedule.day_of_week, schedule]));

    return (
        <TableShell>
            {DAYS.map((_, dayIndex) => (
                <DayRow key={dayIndex} dayIndex={dayIndex} schedule={byDay.get(dayIndex) ?? null} employeeId={employeeId} />
            ))}
        </TableShell>
    );
}
