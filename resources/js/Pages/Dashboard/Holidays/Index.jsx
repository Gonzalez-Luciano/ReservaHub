import { router, useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

export default function Index({ holidays }) {
    const { status, errors } = usePage().props;

    const form = useForm({ name: '', starts_on: '', ends_on: '' });

    const preview = errors?.bookings_preview;

    function submit(event) {
        event.preventDefault();
        form.post('/dashboard/holidays', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    function destroy(holiday) {
        if (confirm(`¿Eliminar el feriado "${holiday.name}"?`)) {
            router.delete(`/dashboard/holidays/${holiday.id}`);
        }
    }

    return (
        <DashboardLayout>
            <div className="mx-auto max-w-3xl p-8">
                <h1 className="mb-6 text-2xl font-bold">Feriados</h1>

                {status && <p className="mb-4 text-sm text-green-700">{status}</p>}

                <form onSubmit={submit} className="mb-8 flex flex-wrap items-end gap-4">
                    <div>
                        <label className="block text-sm font-medium" htmlFor="name">Nombre</label>
                        <input
                            id="name"
                            className="mt-1 rounded-md border px-3 py-2"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="starts_on">Desde</label>
                        <input
                            id="starts_on"
                            type="date"
                            className="mt-1 rounded-md border px-3 py-2"
                            value={form.data.starts_on}
                            onChange={(event) => form.setData('starts_on', event.target.value)}
                        />
                        <InputError message={form.errors.starts_on} />
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="ends_on">Hasta</label>
                        <input
                            id="ends_on"
                            type="date"
                            className="mt-1 rounded-md border px-3 py-2"
                            value={form.data.ends_on}
                            onChange={(event) => form.setData('ends_on', event.target.value)}
                        />
                        <InputError message={form.errors.ends_on} />
                    </div>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                    >
                        Agregar feriado
                    </button>
                </form>

                {preview && (
                    <div className="mb-8 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm">
                        <p className="mb-2 font-semibold">Reservas afectadas (primeras 5):</p>
                        <ul className="list-inside list-disc">
                            {(Array.isArray(preview) ? preview : [preview]).map((line) => (
                                <li key={line}>{line}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b text-gray-500">
                            <th className="py-2">Nombre</th>
                            <th className="py-2">Desde</th>
                            <th className="py-2">Hasta</th>
                            <th className="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {holidays.map((holiday) => (
                            <tr key={holiday.id} className="border-b">
                                <td className="py-2">{holiday.name}</td>
                                <td className="py-2">{holiday.starts_on}</td>
                                <td className="py-2">{holiday.ends_on}</td>
                                <td className="py-2 text-right">
                                    <button onClick={() => destroy(holiday)} className="text-red-600 underline">
                                        Eliminar
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </DashboardLayout>
    );
}
