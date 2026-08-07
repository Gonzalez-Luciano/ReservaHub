import { Link, router, useForm } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

const DAYS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

function ScheduleBreakForm({ schedule }) {
    const { data, setData, post, processing, errors, reset } = useForm({ start_time: '', end_time: '' });

    function submit(e) {
        e.preventDefault();
        post(`/dashboard/schedules/${schedule.id}/breaks`, { onSuccess: () => reset() });
    }

    return (
        <form onSubmit={submit} className="mt-2 flex items-end gap-2">
            <input
                type="time"
                value={data.start_time}
                onChange={(e) => setData('start_time', e.target.value)}
                className="rounded-md border-gray-300 text-xs shadow-sm"
            />
            <input
                type="time"
                value={data.end_time}
                onChange={(e) => setData('end_time', e.target.value)}
                className="rounded-md border-gray-300 text-xs shadow-sm"
            />
            <button type="submit" disabled={processing} className="text-xs underline disabled:opacity-50">
                Agregar pausa
            </button>
            <InputError message={errors.start_time} />
        </form>
    );
}

function TimeOffForm({ employee }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        starts_at: '',
        ends_at: '',
        reason: '',
    });

    function submit(e) {
        e.preventDefault();
        post(`/dashboard/employees/${employee.id}/time-offs`, { onSuccess: () => reset() });
    }

    return (
        <form onSubmit={submit} className="flex max-w-xl flex-wrap items-end gap-4">
            <div>
                <label className="block text-sm font-medium text-gray-700">Desde</label>
                <input
                    type="datetime-local"
                    value={data.starts_at}
                    onChange={(e) => setData('starts_at', e.target.value)}
                    className="mt-1 block rounded-md border-gray-300 shadow-sm"
                />
                <InputError message={errors.starts_at} />
            </div>
            <div>
                <label className="block text-sm font-medium text-gray-700">Hasta</label>
                <input
                    type="datetime-local"
                    value={data.ends_at}
                    onChange={(e) => setData('ends_at', e.target.value)}
                    className="mt-1 block rounded-md border-gray-300 shadow-sm"
                />
                <InputError message={errors.ends_at} />
            </div>
            <div>
                <label className="block text-sm font-medium text-gray-700">Motivo (opcional)</label>
                <input
                    type="text"
                    value={data.reason}
                    onChange={(e) => setData('reason', e.target.value)}
                    className="mt-1 block rounded-md border-gray-300 shadow-sm"
                />
                <InputError message={errors.reason} />
            </div>
            <button
                type="submit"
                disabled={processing}
                className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
            >
                Agregar
            </button>
        </form>
    );
}

export default function Schedule({ employee, schedules, timeOffs }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        day_of_week: 1,
        start_time: '09:00',
        end_time: '18:00',
    });

    function addSchedule(e) {
        e.preventDefault();
        post(`/dashboard/employees/${employee.id}/schedule`, { onSuccess: () => reset('start_time', 'end_time') });
    }

    function removeSchedule(schedule) {
        if (confirm('¿Eliminar este horario?')) {
            router.delete(`/dashboard/schedules/${schedule.id}`);
        }
    }

    return (
        <DashboardLayout>
            <div className="p-8">
                <h1 className="mb-2 text-2xl font-bold">Horario de {employee.name}</h1>
                <Link href="/dashboard/employees" className="mb-6 inline-block text-sm underline">
                    Volver a empleados
                </Link>

                <table className="mb-8 w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Día</th>
                            <th className="py-2">Horario</th>
                            <th className="py-2">Pausas</th>
                            <th className="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {schedules.map((schedule) => (
                            <tr key={schedule.id} className="border-b align-top">
                                <td className="py-2">{DAYS[schedule.day_of_week]}</td>
                                <td className="py-2">{schedule.start_time} - {schedule.end_time}</td>
                                <td className="py-2">
                                    {schedule.breaks.map((scheduleBreak) => (
                                        <div key={scheduleBreak.id} className="flex items-center gap-2">
                                            {scheduleBreak.start_time} - {scheduleBreak.end_time}
                                            <button
                                                onClick={() => router.delete(`/dashboard/schedule-breaks/${scheduleBreak.id}`)}
                                                className="text-red-600 underline"
                                            >
                                                Quitar
                                            </button>
                                        </div>
                                    ))}
                                    <ScheduleBreakForm schedule={schedule} />
                                </td>
                                <td className="py-2 text-right">
                                    <button onClick={() => removeSchedule(schedule)} className="text-red-600 underline">
                                        Eliminar
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <h2 className="mb-4 text-lg font-semibold">Agregar horario</h2>
                <form onSubmit={addSchedule} className="mb-8 flex max-w-xl flex-wrap items-end gap-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Día</label>
                        <select
                            value={data.day_of_week}
                            onChange={(e) => setData('day_of_week', Number(e.target.value))}
                            className="mt-1 block rounded-md border-gray-300 shadow-sm"
                        >
                            {DAYS.map((day, index) => (
                                <option key={index} value={index}>{day}</option>
                            ))}
                        </select>
                        <InputError message={errors.day_of_week} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Desde</label>
                        <input
                            type="time"
                            value={data.start_time}
                            onChange={(e) => setData('start_time', e.target.value)}
                            className="mt-1 block rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.start_time} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Hasta</label>
                        <input
                            type="time"
                            value={data.end_time}
                            onChange={(e) => setData('end_time', e.target.value)}
                            className="mt-1 block rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.end_time} />
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Agregar
                    </button>
                </form>

                <h2 className="mb-4 text-lg font-semibold">Licencias</h2>
                <table className="mb-8 w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Desde</th>
                            <th className="py-2">Hasta</th>
                            <th className="py-2">Motivo</th>
                            <th className="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {timeOffs.map((timeOff) => (
                            <tr key={timeOff.id} className="border-b">
                                <td className="py-2">{timeOff.starts_at}</td>
                                <td className="py-2">{timeOff.ends_at}</td>
                                <td className="py-2">{timeOff.reason}</td>
                                <td className="py-2 text-right">
                                    <button
                                        onClick={() => router.delete(`/dashboard/time-offs/${timeOff.id}`)}
                                        className="text-red-600 underline"
                                    >
                                        Eliminar
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <h2 className="mb-4 text-lg font-semibold">Agregar licencia</h2>
                <TimeOffForm employee={employee} />
            </div>
        </DashboardLayout>
    );
}
