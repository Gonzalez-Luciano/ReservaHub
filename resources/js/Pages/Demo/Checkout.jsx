import { router } from '@inertiajs/react';
import PublicLayout from '../../Components/PublicLayout';
import Alert from '../../Components/ui/Alert';
import Button from '../../Components/ui/Button';
import Surface from '../../Components/ui/Surface';
import PaymentStatusBadge from '../../Components/domain/PaymentStatusBadge';
import { CheckIcon, CrossIcon, SlashCircleIcon } from '../../Components/ui/icons';

const OUTCOMES = [
    {
        outcome: 'approved',
        icon: CheckIcon,
        iconClassName: 'text-confirmed-fg',
        label: 'Simular pago aprobado',
        consequence: 'La reserva pasa a confirmada.',
    },
    {
        outcome: 'rejected',
        icon: CrossIcon,
        iconClassName: 'text-noshow-fg',
        label: 'Simular pago rechazado',
        consequence: 'La reserva sigue pendiente, se puede reintentar.',
    },
    {
        outcome: 'abandoned',
        icon: SlashCircleIcon,
        iconClassName: 'text-muted',
        label: 'Abandonar el pago',
        consequence: 'Al vencer la ventana, la reserva se cancela sola.',
    },
];

function formatMoney(value, currency) {
    return new Intl.NumberFormat('es-AR', { style: 'currency', currency }).format(Number(value ?? 0));
}

export default function Checkout({ payment, outcome_url: outcomeUrl, return_url: returnUrl }) {
    const isPending = payment.status === 'pending';

    return (
        <PublicLayout>
            <div className="mx-auto max-w-[560px] px-6 py-10 lg:py-14">
                <Alert tone="warning" title="ENTORNO DE DEMOSTRACIÓN">
                    No hay proveedor de pago real detrás de esta pantalla. No se cobra nada, no se pide número de
                    tarjeta, código de seguridad ni datos bancarios, y no deberías ingresar ninguna información
                    financiera acá.
                </Alert>

                <h1 className="mt-6 text-2xl font-semibold tracking-[-0.02em]">Pago de seña</h1>

                <div className="mt-4 tnum text-[36px] font-semibold leading-none tracking-[-0.02em]">
                    {formatMoney(payment.amount, payment.currency)}
                </div>

                <div className="mt-2 text-[13px] text-muted">
                    Intento de pago <span className="font-mono">{payment.external_id}</span>
                </div>

                <div className="mt-4">
                    <PaymentStatusBadge status={payment.status} />
                </div>

                {isPending ? (
                    <>
                        <div className="mt-8 flex flex-col gap-3">
                            {OUTCOMES.map(({ outcome, icon: Icon, iconClassName, label, consequence }) => (
                                <Surface key={outcome} className="flex items-center gap-3 p-4">
                                    <Icon size={20} className={`flex-shrink-0 ${iconClassName}`} />
                                    <div className="min-w-0 flex-1">
                                        <div className="text-[14px] font-medium">{label}</div>
                                        <div className="text-[13px] text-muted">{consequence}</div>
                                    </div>
                                    <Button
                                        variant="secondary"
                                        className="h-11 flex-shrink-0"
                                        onClick={() => router.post(outcomeUrl, { outcome })}
                                    >
                                        Elegir
                                    </Button>
                                </Surface>
                            ))}
                        </div>

                        <p className="mt-6 text-[13px] leading-5 text-muted">
                            Este intento forma parte de la demo pública: cualquier resultado que elijas acá se
                            revierte con el reinicio diario de los datos.
                        </p>
                    </>
                ) : (
                    <Button href={returnUrl} variant="primary" className="mt-8 h-11">
                        Volver a mis reservas
                    </Button>
                )}
            </div>
        </PublicLayout>
    );
}
