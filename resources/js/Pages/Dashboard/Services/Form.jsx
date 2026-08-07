import { useForm } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import InputError from '../../../Components/InputError';

export default function Form({ service }) {
    const isEdit = !!service;
    const { data, setData, post, put, processing, errors } = useForm({
        name: service?.name ?? '',
        description: service?.description ?? '',
        duration_minutes: service?.duration_minutes ?? 30,
        buffer_minutes: service?.buffer_minutes ?? 0,
        price: service?.price ?? '',
        deposit_amount: service?.deposit_amount ?? '',
        is_active: service?.is_active ?? true,
    });

    function submit(e) {
        e.preventDefault();
        if (isEdit) {
            put(`/dashboard/services/${service.id}`);
        } else {
            post('/dashboard/services');
        }
    }

    return (
        <DashboardLayout>
            <div className="mx-auto max-w-lg p-8">
                <h1 className="mb-6 text-2xl font-bold">{isEdit ? 'Editar servicio' : 'Nuevo servicio'}</h1>
                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Nombre</label>
                        <input
                            type="text"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Descripción</label>
                        <textarea
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.description} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Duración (minutos)</label>
                        <input
                            type="number"
                            value={data.duration_minutes}
                            onChange={(e) => setData('duration_minutes', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.duration_minutes} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Buffer (minutos)</label>
                        <input
                            type="number"
                            value={data.buffer_minutes}
                            onChange={(e) => setData('buffer_minutes', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.buffer_minutes} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Precio</label>
                        <input
                            type="number"
                            step="0.01"
                            value={data.price}
                            onChange={(e) => setData('price', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.price} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Seña (opcional)</label>
                        <input
                            type="number"
                            step="0.01"
                            value={data.deposit_amount ?? ''}
                            onChange={(e) => setData('deposit_amount', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        />
                        <InputError message={errors.deposit_amount} />
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={data.is_active}
                            onChange={(e) => setData('is_active', e.target.checked)}
                        />
                        Activo
                    </label>
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
