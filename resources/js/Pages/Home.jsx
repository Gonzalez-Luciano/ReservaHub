import { Link } from '@inertiajs/react';
import PublicLayout from '../Components/PublicLayout';
import DemoStrip from '../Components/DemoStrip';
import Button from '../Components/ui/Button';
import { CheckIcon } from '../Components/ui/icons';

// Ventana canónica del negocio: 09:00-18:00, la misma que siembra el
// DemoSeeder y que usa el riel del panel (§5.7). Un solo número para que
// ninguna reserva sembrada quede recortada fuera de la pista.
const WINDOW_START_MINUTES = 9 * 60;
const WINDOW_END_MINUTES = 18 * 60;
const WINDOW_MINUTES = WINDOW_END_MINUTES - WINDOW_START_MINUTES;
const TRACK_WIDTH = 1120;
const PX_PER_MINUTE = TRACK_WIDTH / WINDOW_MINUTES; // 2,074 px/min sobre 1120px.

const HOURS = Array.from({ length: 10 }, (_, index) => 9 + index);

function toMinutes(hhmm) {
    const [hours, minutes] = hhmm.split(':').map(Number);
    return hours * 60 + minutes;
}

function formatLongDate(isoDate) {
    const [year, month, day] = isoDate.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));
    return new Intl.DateTimeFormat('es-AR', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(date);
}

/**
 * Une los bloques ocupados que llegan del servidor con los tramos "sin
 * reserva" entre ellos. La geometría (posición/ancho) es aritmética sobre
 * starts_at/ends_at del servidor; el significado de cada tramo no cambia:
 * un hueco nunca es "libre", solo no tiene una reserva registrada. Puede
 * estar bloqueado por una pausa, una licencia o un feriado que solo
 * AvailabilityService puede resolver.
 */
function buildSegments(occupied) {
    const segments = [];
    let cursor = WINDOW_START_MINUTES;

    for (const block of occupied) {
        const start = toMinutes(block.starts_at);
        const end = toMinutes(block.ends_at);

        if (start > cursor) {
            segments.push({ kind: 'gap', start: cursor, end: start });
        }

        segments.push({ kind: 'occupied', start, end, ...block });
        cursor = Math.max(cursor, end);
    }

    if (cursor < WINDOW_END_MINUTES) {
        segments.push({ kind: 'gap', start: cursor, end: WINDOW_END_MINUTES });
    }

    return segments;
}

function DayTrack({ timeline }) {
    const segments = buildSegments(timeline.occupied);

    return (
        <div className="mt-13">
            <div className="mb-2.5 flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4">
                <span className="micro">
                    {timeline.business_name} · {timeline.employee_name} · {formatLongDate(timeline.date)}
                </span>
                <span className="text-[13px] text-muted">Ocupación real · cada bloque mide lo que dura</span>
            </div>

            {/* 1024/1440: pista horizontal a escala. */}
            <div className="hidden sm:block">
                <div
                    className="relative overflow-hidden rounded-md border border-border bg-surface"
                    style={{ height: 60, width: TRACK_WIDTH, maxWidth: '100%' }}
                >
                    {segments.map((segment, index) => {
                        const left = (segment.start - WINDOW_START_MINUTES) * PX_PER_MINUTE;
                        const width = (segment.end - segment.start) * PX_PER_MINUTE;

                        if (segment.kind === 'occupied') {
                            return (
                                <div
                                    key={index}
                                    className="absolute inset-y-0 flex items-center justify-center bg-slot-taken px-2 text-center text-[12px] text-fg-body"
                                    style={{ left, width }}
                                >
                                    {segment.service_name} · {segment.duration_minutes}′
                                </div>
                            );
                        }

                        return (
                            <div
                                key={index}
                                className="absolute flex items-center justify-center rounded border border-dashed border-track-empty-border text-[12px] text-muted"
                                style={{
                                    left: left + 4,
                                    width: Math.max(width - 8, 0),
                                    top: 8,
                                    bottom: 8,
                                }}
                            >
                                sin reserva
                            </div>
                        );
                    })}
                </div>

                <div className="relative mt-1.5" style={{ height: 20, width: TRACK_WIDTH, maxWidth: '100%' }}>
                    {HOURS.map((hour) => (
                        <span
                            key={hour}
                            className="tnum absolute text-[12px] text-muted"
                            style={{ left: (hour * 60 - WINDOW_START_MINUTES) * PX_PER_MINUTE }}
                        >
                            {String(hour).padStart(2, '0')}
                        </span>
                    ))}
                </div>
            </div>

            {/* 390: lista vertical proporcional, sin grilla horaria. */}
            <div className="flex flex-col gap-1.5 sm:hidden">
                {segments.map((segment, index) => {
                    const minHeight = Math.max((segment.end - segment.start) * 1.1, 30);

                    if (segment.kind === 'occupied') {
                        return (
                            <div
                                key={index}
                                className="flex items-center justify-between gap-3 rounded border border-border bg-slot-taken px-3 text-[13px] text-fg-body"
                                style={{ minHeight }}
                            >
                                <span>{segment.service_name}</span>
                                <span className="tnum shrink-0 text-muted">
                                    {segment.starts_at}–{segment.ends_at}
                                </span>
                            </div>
                        );
                    }

                    return (
                        <div
                            key={index}
                            className="flex items-center justify-center rounded border border-dashed border-track-empty-border px-3 text-[13px] text-muted"
                            style={{ minHeight }}
                        >
                            sin reserva
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function SideCard({ eyebrow, title, items, href, cta }) {
    return (
        <div className="rounded-md border border-border bg-surface p-6">
            <div className="micro">{eyebrow}</div>
            <h3 className="mt-2 text-[20px] font-semibold leading-7 tracking-[-0.01em]">{title}</h3>
            <div className="mt-4 flex flex-col gap-2.5">
                {items.map((item) => (
                    <div key={item} className="flex gap-2.5 text-[15px] leading-[23px]">
                        <CheckIcon className="mt-1.5 shrink-0" size={14} />
                        <span>{item}</span>
                    </div>
                ))}
            </div>
            <Button href={href} variant="secondary" className="mt-5">{cta}</Button>
        </div>
    );
}

function UnderneathItem({ title, description }) {
    return (
        <div>
            <h3 className="text-[19px] font-semibold leading-[26px] tracking-[-0.015em]">{title}</h3>
            <p className="mt-1.5 text-[15px] leading-[25px] text-muted">{description}</p>
        </div>
    );
}

export default function Home({ timeline }) {
    return (
        <PublicLayout>
            <div className="mx-auto max-w-[1440px] px-6 pb-4 pt-14 lg:px-10 lg:pt-19">
                <div className="max-w-[760px]">
                    <div className="micro">Demo de portfolio</div>
                    <h1 className="mt-3.5 text-[36px] font-semibold leading-[40px] tracking-[-0.03em] text-balance sm:text-[48px] sm:leading-[52px] lg:text-[56px] lg:leading-[60px]">
                        Turnos que no se superponen.
                    </h1>
                    <p className="mt-5 max-w-[660px] text-[17px] leading-[27px] text-fg-body sm:text-[18px] sm:leading-[29px]">
                        ReservaHub es un sistema de reservas por franjas horarias para peluquerías, estudios,
                        gimnasios y profesionales que trabajan con turnos. Esta demo tiene los dos lados
                        abiertos: el del negocio que administra su agenda y el del cliente que reserva.
                    </p>

                    <div className="mt-7 flex flex-wrap items-center gap-2.5">
                        <Button href="/negocios" variant="primary" size="lg">Ver negocios y reservar</Button>
                        <Button href="/login" variant="secondary" size="lg">Entrar como negocio</Button>
                    </div>
                    <p className="mt-3 text-[13px] leading-5 text-muted">
                        Hay cuentas de demostración listas para usar.
                    </p>
                </div>

                {timeline && <DayTrack timeline={timeline} />}

                <div className={timeline ? 'mt-9' : 'mt-13'}>
                    <DemoStrip />
                </div>
            </div>

            <div className="mx-auto max-w-[1440px] px-6 pt-16 lg:px-10 lg:pt-20">
                <h2 className="text-[24px] font-semibold leading-9 tracking-[-0.02em] sm:text-[28px]">
                    Dos lados, una sola aplicación
                </h2>
                <p className="mt-2 max-w-[660px] text-[16px] leading-[26px] text-muted">
                    Podés recorrer los dos con sesiones distintas. Abrí una en una ventana normal y la otra en
                    incógnito para verlas a la vez.
                </p>

                <div className="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <SideCard
                        eyebrow="Del lado del negocio"
                        title="Administrar la agenda"
                        items={[
                            'Confirmar, reprogramar, cancelar, completar y marcar ausencias',
                            'Cargar servicios con duración, precio y seña',
                            'Definir horarios semanales, pausas, licencias y feriados',
                            'Invitar personal por email y darle o quitarle acceso',
                        ]}
                        href="/login"
                        cta="Entrar como negocio"
                    />
                    <SideCard
                        eyebrow="Del lado del cliente"
                        title="Reservar un turno"
                        items={[
                            'Elegir negocio, servicio, profesional y horario libre',
                            'Pagar la seña cuando el servicio la pide',
                            'Ver el estado de cada reserva y su comprobante de seña',
                            'Reprogramar o cancelar dentro del plazo que fija el negocio',
                        ]}
                        href="/negocios"
                        cta="Ver negocios"
                    />
                </div>
            </div>

            <div className="mx-auto max-w-[1440px] px-6 pt-16 lg:px-10 lg:pt-19">
                <h2 className="text-[24px] font-semibold leading-9 tracking-[-0.02em] sm:text-[28px]">
                    Lo que pasa por debajo
                </h2>
                <p className="mt-2 max-w-[660px] text-[16px] leading-[26px] text-muted">
                    Cuatro comportamientos que se observan usando la demo, no capturas de pantalla.
                </p>

                <div className="mt-8 grid max-w-[1060px] grid-cols-1 gap-x-18 gap-y-9 lg:grid-cols-2">
                    <UnderneathItem
                        title="La disponibilidad se calcula en el servidor"
                        description="El horario semanal de cada persona, menos sus pausas, sus licencias, los feriados del negocio y las reservas que ya existen. Lo que queda son los turnos que ves."
                    />
                    <UnderneathItem
                        title="Dos personas no pueden tomar el mismo turno"
                        description="La disponibilidad se vuelve a verificar en el momento de guardar. Si alguien se te adelantó por un segundo, la reserva se rechaza en vez de duplicarse."
                    />
                    <UnderneathItem
                        title="La agenda se actualiza sola"
                        description="Cuando un cliente reserva o paga, la pantalla del negocio lo refleja sin que nadie recargue. Probalo con dos ventanas abiertas."
                    />
                    <UnderneathItem
                        title="La seña tiene vencimiento"
                        description="Una reserva que pide seña queda pendiente hasta que el pago se aprueba. Si nadie paga dentro de la ventana, se cancela sola y el horario vuelve a quedar libre."
                    />
                </div>

                <p className="mt-8 max-w-[900px] text-[15px] leading-[25px] text-muted">
                    Hay además cuatro roles —dueño, administrador, empleado y cliente— y dos negocios de
                    demostración que no ven la agenda del otro. Las confirmaciones, reprogramaciones y
                    recordatorios salen por email de verdad y podés leerlos en el{' '}
                    <Link href="/como-funciona" className="text-fg underline">buzón de la demo</Link>, que
                    también es compartido.
                </p>
            </div>

            <div className="h-16 lg:h-18" />
        </PublicLayout>
    );
}
