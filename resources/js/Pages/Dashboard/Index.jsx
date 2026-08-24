import { Link } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import PageHeader from '../../Components/ui/PageHeader';
import StatCard from '../../Components/ui/StatCard';
import EmptyState from '../../Components/ui/EmptyState';
import DayRail from '../../Components/domain/DayRail';
import { BOOKING_SPINE } from '../../Components/domain/BookingStatusBadge';
import { CheckCircleIcon } from '../../Components/ui/icons';

// Ventana canónica del riel: idéntica a la del DemoSeeder y a la tira del
// Home (§5.7). El servidor no la calcula: es presentación pura.
const DAY_WINDOW = { start: '09:00', end: '18:00' };

const STATUS_LABELS = {
    pending: 'pendientes',
    confirmed: 'confirmadas',
    completed: 'completadas',
    cancelled: 'canceladas',
    no_show: 'ausencias',
};

function formatLongDate(timezone) {
    const text = new Intl.DateTimeFormat('es-AR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        timeZone: timezone,
    }).format(new Date());

    return text.charAt(0).toUpperCase() + text.slice(1);
}

function formatTime(isoOrHhmm) {
    const date = new Date(isoOrHhmm);
    return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

function formatMoney(amount) {
    const value = Number(amount);
    if (!Number.isFinite(value) || value <= 0) return null;
    return `$${value.toLocaleString('es-AR')}`;
}

function todayBreakdown(byStatus) {
    return Object.entries(byStatus ?? {})
        .filter(([, count]) => count > 0)
        .map(([status, count]) => `${count} ${STATUS_LABELS[status] ?? status}`)
        .join(' · ');
}

function AttentionRow({ item }) {
    return (
        <div className="grid grid-cols-[3px_minmax(0,1fr)] gap-3 border-b border-border py-3 last:border-b-0">
            <div className={BOOKING_SPINE[item.status] ?? BOOKING_SPINE.pending} />
            <div className="min-w-0">
                <div className="flex items-center justify-between gap-2">
                    <span className="truncate text-[14px] font-medium">{item.service_name}</span>
                    <span className="tnum shrink-0 text-[13px] text-muted">{formatTime(item.starts_at)}</span>
                </div>
                <div className="mt-0.5 truncate text-[12px] text-muted">
                    {item.employee_name} · {item.customer_name}
                </div>
                <div className="mt-1 flex items-center gap-1.5 text-[12px]">
                    {item.kind === 'expiring_soon' ? (
                        <span className="font-medium text-pending-fg">
                            Vence {formatTime(item.payment_expires_at)}
                        </span>
                    ) : (
                        <span className="text-muted">Esperando seña</span>
                    )}
                    {formatMoney(item.deposit_amount) && (
                        <span className="text-muted">· {formatMoney(item.deposit_amount)}</span>
                    )}
                </div>
            </div>
        </div>
    );
}

function Shortcut({ href, children }) {
    return (
        <Link href={href} className="text-[13px] leading-[26px] hover:underline">
            {children}
        </Link>
    );
}

export default function Index({ business, metrics, today, attention }) {
    return (
        <DashboardLayout>
            <PageHeader
                title="Panel"
                subtitle={`${formatLongDate(business.timezone)} · ${business.timezone}`}
            />

            <div className="mb-6 flex flex-wrap items-end gap-8 border-b border-border pb-5 sm:gap-11">
                <div>
                    <div className="flex items-baseline gap-3">
                        <span className="tnum text-[52px] leading-[56px] font-semibold tracking-[-0.04em] sm:text-[68px] sm:leading-[64px]">
                            {metrics.today_total}
                        </span>
                        <span className="text-[18px] font-semibold tracking-[-0.02em] sm:text-[22px]">turnos hoy</span>
                    </div>
                    {todayBreakdown(metrics.today_by_status) && (
                        <div className="mt-1.5 text-[13px] text-muted">{todayBreakdown(metrics.today_by_status)}</div>
                    )}
                </div>

                <div className="flex flex-wrap items-end gap-6 sm:gap-9">
                    <StatCard label="Esperando seña" value={metrics.awaiting_deposit} hint="pendientes" />
                    <div className="hidden h-[38px] w-px bg-border sm:block" aria-hidden="true" />
                    <StatCard
                        label="Vence pronto"
                        value={metrics.expiring_soon}
                        hint="en 15 min"
                        tone={metrics.expiring_soon > 0 ? 'pending' : undefined}
                    />
                    <div className="hidden h-[38px] w-px bg-border sm:block" aria-hidden="true" />
                    <StatCard label="Próximos 7 días" value={metrics.upcoming_7d} hint="confirmadas" />
                </div>
            </div>

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_400px]">
                <div>
                    <div className="mb-2 flex items-center gap-2.5">
                        <span className="micro">Hoy · la jornada</span>
                        <div className="h-px flex-grow bg-border" aria-hidden="true" />
                        <Link href="/dashboard/bookings" className="text-[13px] hover:underline">Ver todas</Link>
                    </div>

                    {today.length === 0 && (
                        <div className="mb-3">
                            <EmptyState
                                icon={CheckCircleIcon}
                                title="No hay turnos hoy"
                                description="Cuando se cargue una reserva para hoy, va a aparecer en el riel."
                                action={(
                                    <Link href="/dashboard/bookings/create" className="text-[13px] font-medium underline">
                                        Cargar una reserva
                                    </Link>
                                )}
                            />
                        </div>
                    )}

                    <DayRail bookings={today} window={DAY_WINDOW} />
                </div>

                <div>
                    <div className="mb-2 flex items-center gap-2.5">
                        <span className="micro">Requieren atención</span>
                        <div className="h-px flex-grow bg-border" aria-hidden="true" />
                    </div>

                    {attention.length === 0 ? (
                        <EmptyState
                            title="Nada requiere atención"
                            description="Acá aparecen las reservas que esperan el pago de la seña y las que están por vencer."
                        />
                    ) : (
                        <div className="rounded-md border border-border bg-surface px-4">
                            {attention.map((item) => (
                                <AttentionRow key={item.id} item={item} />
                            ))}
                        </div>
                    )}

                    <div className="mt-5">
                        <div className="micro mb-1.5">Atajos</div>
                        <div className="flex flex-col">
                            <Shortcut href="/dashboard/bookings/create">Cargar una reserva a mano</Shortcut>
                            <Shortcut href="/dashboard/employees">Editar horarios del personal</Shortcut>
                            <Shortcut href="/dashboard/holidays">Agregar un feriado</Shortcut>
                        </div>
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}
