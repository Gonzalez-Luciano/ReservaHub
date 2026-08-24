import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import DashboardLayout from '../../../Components/DashboardLayout';
import PageHeader from '../../../Components/ui/PageHeader';
import Button from '../../../Components/ui/Button';
import Surface from '../../../Components/ui/Surface';
import TableShell from '../../../Components/ui/TableShell';
import EmptyState from '../../../Components/ui/EmptyState';
import Alert from '../../../Components/ui/Alert';
import Toast from '../../../Components/ui/Toast';
import { FormField, Input } from '../../../Components/ui/Field';
import { HolidayIcon } from '../../../Components/ui/icons';

function formatDay(value) {
    return new Date(value).toLocaleDateString('es-AR', { dateStyle: 'medium', timeZone: 'UTC' });
}

function HolidayRow({ holiday, onDelete }) {
    return (
        <div className="flex items-center justify-between gap-4 px-4 py-3">
            <div>
                <div className="text-[14px] font-medium">{holiday.name}</div>
                <div className="tnum text-[13px] text-muted">
                    {formatDay(holiday.starts_on)}
                    {holiday.ends_on !== holiday.starts_on && ` – ${formatDay(holiday.ends_on)}`}
                </div>
            </div>
            <button
                type="button"
                onClick={() => onDelete(holiday)}
                className="text-[13px] text-noshow-fg underline hover:no-underline"
            >
                Eliminar
            </button>
        </div>
    );
}

export default function Index({ holidays }) {
    const { status, errors } = usePage().props;
    const [toastMessage, setToastMessage] = useState(status ?? null);

    useEffect(() => {
        if (status) setToastMessage(status);
    }, [status]);

    const form = useForm({ name: '', starts_on: '', ends_on: '' });

    const conflictMessage = errors?.starts_on;
    const preview = errors?.bookings_preview;
    const previewLines = preview ? (Array.isArray(preview) ? preview : [preview]) : [];

    function submit(event) {
        event.preventDefault();
        form.post('/dashboard/holidays', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    function destroy(holiday) {
        if (confirm(`¿Eliminar el feriado "${holiday.name}"?`)) {
            router.delete(`/dashboard/holidays/${holiday.id}`, { preserveScroll: true });
        }
    }

    return (
        <DashboardLayout>
            <div className="space-y-8">
                <PageHeader title="Feriados" subtitle="Días en los que el negocio no atiende." />

                {previewLines.length > 0 && (
                    <Alert tone="warning" title="No se pudo crear el feriado">
                        <p>{conflictMessage}</p>
                        <ul className="mt-2 list-inside list-disc space-y-1">
                            {previewLines.map((line, index) => (
                                // Las líneas son texto formateado, no tienen id propio.
                                <li key={index}>{line}</li>
                            ))}
                        </ul>
                    </Alert>
                )}

                <Surface>
                    <form onSubmit={submit} className="grid gap-4 p-5 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end">
                        <FormField id="name" label="Nombre" error={form.errors.name}>
                            {(props) => (
                                <Input
                                    {...props}
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    placeholder="ej. Navidad"
                                />
                            )}
                        </FormField>

                        <FormField id="starts_on" label="Desde" error={form.errors.starts_on}>
                            {(props) => (
                                <Input
                                    {...props}
                                    type="date"
                                    value={form.data.starts_on}
                                    onChange={(event) => form.setData('starts_on', event.target.value)}
                                />
                            )}
                        </FormField>

                        <FormField id="ends_on" label="Hasta" error={form.errors.ends_on}>
                            {(props) => (
                                <Input
                                    {...props}
                                    type="date"
                                    value={form.data.ends_on}
                                    onChange={(event) => form.setData('ends_on', event.target.value)}
                                />
                            )}
                        </FormField>

                        <Button type="submit" variant="primary" disabled={form.processing}>
                            Agregar feriado
                        </Button>
                    </form>
                </Surface>

                {holidays.length === 0 ? (
                    <EmptyState
                        icon={HolidayIcon}
                        title="Todavía no hay feriados"
                        description="Agregá uno arriba para bloquear ese rango de fechas en la disponibilidad."
                    />
                ) : (
                    <TableShell>
                        {holidays.map((holiday) => (
                            <HolidayRow key={holiday.id} holiday={holiday} onDelete={destroy} />
                        ))}
                    </TableShell>
                )}
            </div>

            <Toast message={toastMessage} onDismiss={() => setToastMessage(null)} />
        </DashboardLayout>
    );
}
