import Button from '../ui/Button';
import { ClockIcon } from '../ui/icons';

function formatCurrency(value, currency) {
    return new Intl.NumberFormat('es-AR', { style: 'currency', currency }).format(value);
}

/**
 * Tarjeta de un servicio en la página pública de un negocio. Muestra nombre,
 * descripción, duración y precio en la moneda del negocio y, cuando el
 * servicio pide seña, el importe exacto — hoy no se anuncia en ninguna
 * otra parte antes de llegar al formulario de reserva.
 */
export default function ServiceCard({ service, currency, href }) {
    const hasDeposit = Number(service.deposit_amount) > 0;

    return (
        <div className="flex h-full flex-col justify-between rounded-md border border-border bg-surface p-5">
            <div>
                <h3 className="text-[17px] font-semibold leading-6 tracking-[-0.01em]">{service.name}</h3>
                {service.description && (
                    <p className="mt-1.5 text-[14px] leading-[22px] text-muted">{service.description}</p>
                )}

                <div className="mt-3.5 flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span className="inline-flex items-center gap-1.5 text-[13px] text-muted">
                        <ClockIcon size={14} />
                        {service.duration_minutes} min
                    </span>
                    <span className="tnum text-[16px] font-semibold tracking-[-0.01em]">
                        {formatCurrency(service.price, currency)}
                    </span>
                </div>

                {hasDeposit && (
                    <p className="mt-2 text-[13px] leading-5 text-muted">
                        Requiere seña de{' '}
                        <span className="tnum font-medium text-fg-body">
                            {formatCurrency(service.deposit_amount, currency)}
                        </span>
                    </p>
                )}
            </div>

            {href && (
                <Button href={href} variant="primary" className="mt-4 self-start">
                    Reservar
                </Button>
            )}
        </div>
    );
}
