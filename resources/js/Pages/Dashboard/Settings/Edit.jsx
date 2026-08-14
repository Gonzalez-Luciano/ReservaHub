import { useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

export default function Edit({ business, currencies, timezones }) {
    const { status } = usePage().props;

    const form = useForm({
        name: business.name,
        timezone: business.timezone,
        currency: business.currency,
        cancellation_hours: business.cancellation_hours,
    });

    const timezoneChanged = form.data.timezone !== business.timezone;

    function submit(event) {
        event.preventDefault();
        form.put('/dashboard/settings', { preserveScroll: true });
    }

    return (
        <DashboardLayout>
            <div className="mx-auto max-w-2xl p-8">
                <h1 className="mb-6 text-2xl font-bold">Ajustes del negocio</h1>

                {status && <p className="mb-4 text-sm text-green-700">{status}</p>}

                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium" htmlFor="name">Nombre</label>
                        <input
                            id="name"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="timezone">Zona horaria</label>
                        <select
                            id="timezone"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={form.data.timezone}
                            onChange={(event) => form.setData('timezone', event.target.value)}
                        >
                            {timezones.map((timezone) => (
                                <option key={timezone} value={timezone}>{timezone}</option>
                            ))}
                        </select>
                        <InputError message={form.errors.timezone} />
                        {timezoneChanged && (
                            <p className="mt-1 text-sm text-amber-700">
                                Las reservas ya creadas no se mueven, pero los horarios semanales de tus
                                empleados pasan a interpretarse en la zona nueva. Revisalos después de guardar.
                            </p>
                        )}
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="currency">Moneda</label>
                        <select
                            id="currency"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={form.data.currency}
                            onChange={(event) => form.setData('currency', event.target.value)}
                        >
                            {currencies.map((currency) => (
                                <option key={currency} value={currency}>{currency}</option>
                            ))}
                        </select>
                        <InputError message={form.errors.currency} />
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="cancellation_hours">
                            Horas mínimas para cancelar
                        </label>
                        <input
                            id="cancellation_hours"
                            type="number"
                            min="0"
                            max="168"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={form.data.cancellation_hours}
                            onChange={(event) => form.setData('cancellation_hours', event.target.value)}
                        />
                        <InputError message={form.errors.cancellation_hours} />
                    </div>

                    <p className="text-sm text-gray-600">
                        La dirección pública de tu negocio (<code>/businesses/{business.slug}</code>) no se
                        puede cambiar: los enlaces ya compartidos dejarían de funcionar.
                    </p>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                    >
                        Guardar ajustes
                    </button>
                </form>
            </div>
        </DashboardLayout>
    );
}
