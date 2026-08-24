import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import PageHeader from '../../../Components/ui/PageHeader';
import Button from '../../../Components/ui/Button';
import Surface from '../../../Components/ui/Surface';
import Toast from '../../../Components/ui/Toast';
import { FormField, Input, Select } from '../../../Components/ui/Field';

export default function Edit({ business, currencies, timezones }) {
    const { status } = usePage().props;
    const [toastMessage, setToastMessage] = useState(status ?? null);

    useEffect(() => {
        if (status) setToastMessage(status);
    }, [status]);

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
            <div className="mx-auto max-w-2xl space-y-8">
                <PageHeader title="Ajustes del negocio" />

                <form onSubmit={submit} className="space-y-6">
                    {/* Bloque de identidad */}
                    <Surface>
                        <div className="space-y-4 p-6">
                            <h2 className="text-[15px] font-semibold">Identidad</h2>

                            <FormField id="name" label="Nombre" error={form.errors.name}>
                                {(props) => (
                                    <Input
                                        {...props}
                                        value={form.data.name}
                                        onChange={(event) => form.setData('name', event.target.value)}
                                    />
                                )}
                            </FormField>

                            <p className="text-[13px] leading-5 text-muted">
                                La dirección pública de tu negocio (<code>/negocios/{business.slug}</code>) no se
                                puede cambiar: los enlaces ya compartidos dejarían de funcionar.
                            </p>
                        </div>
                    </Surface>

                    {/* Bloque de operación */}
                    <Surface>
                        <div className="space-y-4 p-6">
                            <h2 className="text-[15px] font-semibold">Operación</h2>

                            <FormField id="timezone" label="Zona horaria" error={form.errors.timezone}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.timezone}
                                        onChange={(event) => form.setData('timezone', event.target.value)}
                                    >
                                        {timezones.map((timezone) => (
                                            <option key={timezone} value={timezone}>{timezone}</option>
                                        ))}
                                    </Select>
                                )}
                            </FormField>
                            {timezoneChanged && (
                                <p className="text-[13px] leading-5 text-pending-fg">
                                    Las reservas ya creadas no se mueven, pero los horarios semanales de tus
                                    empleados pasan a interpretarse en la zona nueva. Revisalos después de guardar.
                                </p>
                            )}

                            <FormField id="currency" label="Moneda" error={form.errors.currency}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.currency}
                                        onChange={(event) => form.setData('currency', event.target.value)}
                                    >
                                        {currencies.map((currency) => (
                                            <option key={currency} value={currency}>{currency}</option>
                                        ))}
                                    </Select>
                                )}
                            </FormField>
                        </div>
                    </Surface>

                    {/* Bloque de reservas */}
                    <Surface>
                        <div className="space-y-4 p-6">
                            <h2 className="text-[15px] font-semibold">Reservas</h2>

                            <FormField
                                id="cancellation_hours"
                                label="Horas mínimas para cancelar"
                                error={form.errors.cancellation_hours}
                                hint="Los clientes no pueden cancelar una reserva dentro de esta ventana antes del turno."
                            >
                                {(props) => (
                                    <Input
                                        {...props}
                                        type="number"
                                        min="0"
                                        max="168"
                                        value={form.data.cancellation_hours}
                                        onChange={(event) => form.setData('cancellation_hours', event.target.value)}
                                    />
                                )}
                            </FormField>
                        </div>
                    </Surface>

                    {/* Acciones */}
                    <div className="flex items-center justify-end gap-3 pt-2">
                        <Button variant="primary" type="submit" disabled={form.processing}>
                            Guardar ajustes
                        </Button>
                    </div>
                </form>
            </div>

            <Toast message={toastMessage} onDismiss={() => setToastMessage(null)} />
        </DashboardLayout>
    );
}
