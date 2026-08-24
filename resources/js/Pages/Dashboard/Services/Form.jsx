import { useForm } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import PageHeader from '../../../Components/ui/PageHeader';
import Button from '../../../Components/ui/Button';
import Surface from '../../../Components/ui/Surface';
import { FormField, Input, Textarea } from '../../../Components/ui/Field';

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
            <div className="mx-auto max-w-2xl space-y-8">
                <PageHeader
                    title={isEdit ? 'Editar servicio' : 'Nuevo servicio'}
                />

                <form onSubmit={submit} className="space-y-6">
                    {/* Bloque de identidad */}
                    <Surface>
                        <div className="space-y-4 p-6">
                            <h2 className="text-[15px] font-semibold">Identidad</h2>

                            <FormField
                                id="name"
                                label="Nombre"
                                error={errors.name}
                            >
                                {(props) => (
                                    <Input
                                        {...props}
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="ej. Corte de cabello"
                                    />
                                )}
                            </FormField>

                            <FormField
                                id="description"
                                label="Descripción"
                                error={errors.description}
                            >
                                {(props) => (
                                    <Textarea
                                        {...props}
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        placeholder="Descripción del servicio (opcional)"
                                        rows="3"
                                    />
                                )}
                            </FormField>
                        </div>
                    </Surface>

                    {/* Bloque de tiempo */}
                    <Surface>
                        <div className="space-y-4 p-6">
                            <h2 className="text-[15px] font-semibold">Tiempo</h2>

                            <FormField
                                id="duration_minutes"
                                label="Duración (minutos)"
                                error={errors.duration_minutes}
                                hint="Tiempo que toma el servicio"
                            >
                                {(props) => (
                                    <Input
                                        {...props}
                                        type="number"
                                        min="1"
                                        value={data.duration_minutes}
                                        onChange={(e) => setData('duration_minutes', e.target.value)}
                                    />
                                )}
                            </FormField>

                            <FormField
                                id="buffer_minutes"
                                label="Buffer (minutos)"
                                error={errors.buffer_minutes}
                                hint="Tiempo entre citas para preparación"
                            >
                                {(props) => (
                                    <Input
                                        {...props}
                                        type="number"
                                        min="0"
                                        value={data.buffer_minutes}
                                        onChange={(e) => setData('buffer_minutes', e.target.value)}
                                    />
                                )}
                            </FormField>
                        </div>
                    </Surface>

                    {/* Bloque de dinero */}
                    <Surface>
                        <div className="space-y-4 p-6">
                            <h2 className="text-[15px] font-semibold">Dinero</h2>

                            <FormField
                                id="price"
                                label="Precio"
                                error={errors.price}
                            >
                                {(props) => (
                                    <Input
                                        {...props}
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={data.price}
                                        onChange={(e) => setData('price', e.target.value)}
                                        placeholder="0.00"
                                    />
                                )}
                            </FormField>

                            <FormField
                                id="deposit_amount"
                                label="Seña (opcional)"
                                error={errors.deposit_amount}
                                hint="Monto a cobrar como adelanto. El cliente debe pagar la seña antes de confirmar la reserva."
                            >
                                {(props) => (
                                    <Input
                                        {...props}
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={data.deposit_amount ?? ''}
                                        onChange={(e) => setData('deposit_amount', e.target.value)}
                                        placeholder="0.00"
                                    />
                                )}
                            </FormField>
                        </div>
                    </Surface>

                    {/* Bloque de estado */}
                    <Surface>
                        <div className="space-y-4 p-6">
                            <h2 className="text-[15px] font-semibold">Estado</h2>

                            <label htmlFor="is_active" className="flex items-center gap-3">
                                <input
                                    id="is_active"
                                    type="checkbox"
                                    checked={data.is_active}
                                    onChange={(e) => setData('is_active', e.target.checked)}
                                    className="h-4 w-4 rounded border border-border"
                                />
                                <span className="text-[15px]">Activo</span>
                            </label>
                            <p className="text-xs leading-5 text-muted">
                                Los servicios inactivos no aparecen en la página de reservas.
                            </p>
                        </div>
                    </Surface>

                    {/* Acciones */}
                    <div className="flex items-center justify-end gap-3 pt-2">
                        <Button
                            variant="secondary"
                            onClick={() => window.history.back()}
                            type="button"
                        >
                            Cancelar
                        </Button>
                        <Button
                            variant="primary"
                            type="submit"
                            disabled={processing}
                        >
                            {isEdit ? 'Guardar cambios' : 'Crear servicio'}
                        </Button>
                    </div>
                </form>
            </div>
        </DashboardLayout>
    );
}
