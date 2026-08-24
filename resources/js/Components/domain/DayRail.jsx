import { BOOKING_SPINE } from './BookingStatusBadge';

// Ventana canónica del negocio: 09:00-18:00, la misma que siembra el
// DemoSeeder, que usa la tira del Home (§5.7) y que este riel recibe por
// prop. Un solo número para que ninguna reserva sembrada quede recortada.
const PX_PER_MINUTE = 1.1;

function toMinutes(isoOrHhmm) {
    const date = new Date(isoOrHhmm);
    return date.getHours() * 60 + date.getMinutes();
}

// Racimos de solapes → asignación voraz de carril → ancho = 100% / carriles.
// Un racimo sin solapes conserva un solo carril y ocupa el ancho completo, así
// que los datos sembrados se ven igual que el artboard aprobado.
export function assignLanes(bookings) {
    const sorted = [...bookings].sort((a, b) =>
        toMinutes(a.starts_at) - toMinutes(b.starts_at)
        || toMinutes(a.ends_at) - toMinutes(b.ends_at)
        || a.id - b.id
    );

    const placed = [];
    let cluster = [];
    let clusterEnd = -Infinity;

    const flush = () => {
        const lanes = [];
        for (const booking of cluster) {
            let lane = lanes.findIndex((end) => end <= toMinutes(booking.starts_at));
            if (lane === -1) {
                lane = lanes.length;
                lanes.push(0);
            }
            lanes[lane] = toMinutes(booking.ends_at);
            placed.push({ ...booking, lane, lanes: 0 });
        }
        const count = lanes.length;
        for (let i = placed.length - cluster.length; i < placed.length; i += 1) {
            placed[i].lanes = count;
        }
        cluster = [];
        clusterEnd = -Infinity;
    };

    for (const booking of sorted) {
        if (cluster.length > 0 && toMinutes(booking.starts_at) >= clusterEnd) {
            flush();
        }
        cluster.push(booking);
        clusterEnd = Math.max(clusterEnd, toMinutes(booking.ends_at));
    }
    if (cluster.length > 0) flush();

    return placed;
}

function windowToMinutes(hhmm) {
    const [hours, minutes] = hhmm.split(':').map(Number);
    return hours * 60 + minutes;
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

// Solo pending y confirmed tienen un color de bloque propio en la escala de
// estado (§ tokens); el resto comparte el fondo neutro "deshabilitado" — la
// diferencia real de esos estados ya la lleva la tira de color (BOOKING_SPINE).
const BLOCK_BG = {
    pending: 'bg-pending-block',
    confirmed: 'bg-confirmed-block',
    completed: 'bg-surface-disabled',
    cancelled: 'bg-surface-disabled',
    no_show: 'bg-surface-disabled',
};

function BlockBody({ booking, tall }) {
    const cancelled = booking.status === 'cancelled';
    const deposit = booking.status === 'pending' ? formatMoney(booking.deposit_amount) : null;

    const summary = [booking.service_name, booking.employee_name, booking.customer_name].filter(Boolean).join(' · ');

    if (!tall) {
        return (
            <div className="flex min-w-0 items-center gap-2 px-2.5">
                <span
                    className={`tnum text-[13px] font-semibold ${cancelled ? 'text-muted line-through' : ''}`}
                >
                    {formatTime(booking.starts_at)}
                </span>
                <span className={`truncate text-[13px] ${cancelled ? 'text-muted' : ''}`}>
                    {summary}
                    {cancelled && ' — cancelada'}
                </span>
            </div>
        );
    }

    return (
        <div className="flex min-w-0 flex-col justify-center gap-0.5 px-2.5">
            <div className="flex items-center gap-2">
                <span className={`tnum text-[13px] font-semibold ${cancelled ? 'text-muted line-through' : ''}`}>
                    {formatTime(booking.starts_at)}
                </span>
                <span className={`truncate text-[13px] ${cancelled ? 'text-muted' : ''}`}>
                    {summary}
                    {cancelled && ' — cancelada'}
                </span>
            </div>
            <div className="tnum text-[12px] text-muted">{booking.duration_minutes} min</div>
            {deposit && (
                <div className="tnum text-[12px] text-confirmed-fg">
                    Seña {deposit}{booking.payment_expires_at ? ` · vence ${formatTime(booking.payment_expires_at)}` : ''}
                </div>
            )}
        </div>
    );
}

function DesktopRail({ bookings, startMinutes, endMinutes, hours }) {
    const height = (endMinutes - startMinutes) * PX_PER_MINUTE;
    const lanes = assignLanes(bookings);

    return (
        <div className="hidden rounded-md border border-border bg-surface py-3.5 pl-0 pr-4 sm:block">
            <div className="relative" style={{ height }}>
                {hours.map((minute) => (
                    <div key={minute} className="absolute left-0 w-full border-t border-rule-faint" style={{ top: (minute - startMinutes) * PX_PER_MINUTE }}>
                        <span
                            className="tnum absolute left-4 -translate-y-2 bg-surface pr-1.5 text-[12px] text-muted"
                        >
                            {String(Math.floor(minute / 60)).padStart(2, '0')}:00
                        </span>
                    </div>
                ))}

                {lanes.map((booking) => {
                    const start = toMinutes(booking.starts_at);
                    const top = (start - startMinutes) * PX_PER_MINUTE;
                    const blockHeight = Math.max(booking.duration_minutes * PX_PER_MINUTE, 24);
                    // 72px reservados para las etiquetas de hora; el resto del
                    // ancho se reparte entre los carriles del racimo. Un
                    // racimo de un solo carril ocupa ese resto completo.
                    const left = `calc(72px + (100% - 72px) * ${booking.lane} / ${booking.lanes})`;
                    const width = `calc((100% - 72px) / ${booking.lanes})`;

                    return (
                        <div
                            key={booking.id}
                            className={`absolute grid grid-cols-[3px_minmax(0,1fr)] overflow-hidden rounded-r ${BLOCK_BG[booking.status] ?? 'bg-surface-disabled'}`}
                            style={{ top, height: blockHeight, left, width }}
                        >
                            <div className={BOOKING_SPINE[booking.status] ?? 'bg-cancelled-fg'} />
                            <div className="flex min-w-0 items-center">
                                <BlockBody booking={booking} tall={blockHeight >= 50} />
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function MobileRail({ bookings }) {
    const sorted = [...bookings].sort((a, b) =>
        toMinutes(a.starts_at) - toMinutes(b.starts_at)
        || toMinutes(a.ends_at) - toMinutes(b.ends_at)
        || a.id - b.id
    );

    return (
        <div className="flex flex-col gap-1.5 sm:hidden">
            {sorted.map((booking) => {
                const cancelled = booking.status === 'cancelled';
                const minHeight = Math.max(booking.duration_minutes * PX_PER_MINUTE, 40);

                return (
                    <div
                        key={booking.id}
                        className={`grid grid-cols-[3px_minmax(0,1fr)] overflow-hidden rounded ${BLOCK_BG[booking.status] ?? 'bg-surface-disabled'}`}
                        style={{ minHeight }}
                    >
                        <div className={BOOKING_SPINE[booking.status] ?? 'bg-cancelled-fg'} />
                        <div className="flex min-w-0 items-center px-3 py-1.5">
                            <BlockBody booking={booking} tall={minHeight >= 56} />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

export default function DayRail({ bookings, window: timeWindow }) {
    const startMinutes = windowToMinutes(timeWindow?.start ?? '09:00');
    const endMinutes = windowToMinutes(timeWindow?.end ?? '18:00');
    const hours = [];
    for (let minute = startMinutes; minute <= endMinutes; minute += 60) {
        hours.push(minute);
    }

    const visible = bookings ?? [];

    return (
        <>
            <DesktopRail bookings={visible} startMinutes={startMinutes} endMinutes={endMinutes} hours={hours} />
            <MobileRail bookings={visible} />
        </>
    );
}
