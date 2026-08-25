import PublicLayout from '../Components/PublicLayout';
import Button from '../Components/ui/Button';
import DemoResetCountdown from '../Components/domain/DemoResetCountdown';
import { CheckIcon, ExternalIcon, MailIcon, WarningIcon } from '../Components/ui/icons';

// Público por definición: todo lo que empieza con VITE_ se compila dentro
// del bundle (.env.example:54-56). Sin definirla, el CTA no tiene nada que
// renderizar y el resto de la página funciona igual — cero polling, cero
// verificación de disponibilidad del buzón.
const mailUrl = import.meta.env.VITE_DEMO_MAIL_URL;

const STAFF_STEPS = [
    { title: 'Ingresá al panel', description: 'Turnos de hoy, lo que espera confirmación y las señas por vencer.' },
    { title: 'Gestioná una reserva', description: 'Confirmar, reprogramar, cancelar, completar o marcar ausencia.' },
    { title: 'Mirá los servicios', description: 'Duración, precio y cuáles piden seña.' },
    { title: 'Abrí el horario de una persona', description: 'Horario semanal, pausas y licencias: de ahí sale la disponibilidad.' },
    { title: 'Cargá un feriado', description: 'Ese día deja de ofrecer turnos. Si choca con reservas, te avisa antes.' },
];

const CUSTOMER_STEPS = [
    { title: 'Elegí un negocio', description: 'Hay dos, con servicios y equipos distintos.' },
    { title: 'Elegí servicio, profesional y día', description: 'Solo aparecen los horarios que están libres de verdad.' },
    { title: 'Reservá', description: 'Si el servicio pide seña, el turno queda pendiente hasta pagarla.' },
    { title: 'Pagá en el checkout simulado', description: 'Aprobar, rechazar o abandonar. Los tres caminos son reales.' },
    { title: 'Mirá tus reservas', description: 'Estado, seña y la opción de reprogramar o cancelar a tiempo.' },
];

const MAIL_STEPS = [
    'Hacé algo que genere un correo: reservar, cancelar o invitar a alguien al equipo.',
    'Abrí el buzón de la demo.',
    'Buscá el mensaje dirigido a la dirección que usaste vos.',
    'Abrilo y, si tiene un enlace, seguilo desde ahí.',
];

const RESET_ITEMS = ['los datos vuelven al estado inicial', 'se vacía el buzón de correo', 'se pierde lo que hayas creado'];

const FICTICIOUS_DATA_POINTS = [
    <>
        <strong>No ingreses</strong> tu nombre real si podés evitarlo, tu correo personal, información privada ni
        datos de tu negocio.
    </>,
    <>
        <strong>Nunca reutilices</strong> una contraseña que uses en otro servicio. Inventá una descartable, solo
        para esta demo.
    </>,
    <>
        <strong>No hay pagos reales.</strong> No se piden números de tarjeta, códigos de seguridad ni datos
        bancarios en ninguna pantalla.
    </>,
    <>
        <strong>Nada es privado.</strong> Cualquier visitante puede ver lo que cargues, incluidos los correos que
        se generen.
    </>,
];

function NumberedStep({ index, title, description }) {
    return (
        <div className="flex gap-3.5">
            <span className="tnum w-[22px] shrink-0 pt-0.5 text-[13px] font-semibold text-muted">
                {String(index + 1).padStart(2, '0')}
            </span>
            <div>
                <div className="text-[15px] font-medium">{title}</div>
                <div className="mt-0.5 text-[13px] leading-5 text-muted">{description}</div>
            </div>
        </div>
    );
}

function JourneyCard({ label, title, note, steps }) {
    return (
        <div className="rounded-md border border-border bg-surface p-6 lg:p-7">
            <div className="micro">{label}</div>
            <h3 className="mt-2 text-[19px] font-semibold leading-7 tracking-[-0.015em] sm:text-[21px] sm:leading-8">
                {title}
            </h3>

            {note}

            <div className="mt-4.5 flex flex-col gap-3">
                {steps.map((step, index) => (
                    <NumberedStep key={step.title} index={index} title={step.title} description={step.description} />
                ))}
            </div>
        </div>
    );
}

export default function ComoFunciona({ demoPassword }) {
    return (
        <PublicLayout>
            <div className="mx-auto max-w-[1440px] px-6 pb-4 pt-14 lg:px-10 lg:pt-19">
                {/* Título e introducción */}
                <div className="max-w-[820px]">
                    <h1 className="text-[32px] font-semibold leading-9 tracking-[-0.03em] text-balance sm:text-[40px] sm:leading-[48px] lg:text-[44px] lg:leading-[48px]">
                        Cómo funciona la demo
                    </h1>
                    <p className="mt-4.5 max-w-[760px] text-[17px] leading-[27px] text-fg-body sm:text-[18px] sm:leading-[29px]">
                        ReservaHub es un proyecto de portfolio, no un servicio comercial en funcionamiento. Te
                        explico cómo recorrerlo en pocos minutos, qué esperar de un entorno compartido y qué
                        información conviene usar.
                    </p>
                </div>

                {/* Es una demo compartida + Próximo reinicio */}
                <div className="mt-11 grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_420px] lg:mt-13">
                    <div className="rounded-md border border-border bg-surface p-6 lg:p-7">
                        <div className="micro">Es una demo compartida</div>
                        <h2 className="mt-2.5 text-[21px] font-semibold leading-8 tracking-[-0.02em] sm:text-[24px]">
                            Puede haber otras personas usándola ahora mismo
                        </h2>
                        <p className="mt-3 max-w-[640px] text-[15px] leading-[25px] text-fg-body sm:text-[16px] sm:leading-[26px]">
                            Te vas a encontrar con reservas, clientes y correos que no son parte de tu prueba. Es el
                            comportamiento esperado: hay una sola instalación y todo el mundo entra a la misma.
                        </p>
                        <p className="mt-3 max-w-[640px] text-[15px] leading-[25px] text-fg-body sm:text-[16px] sm:leading-[26px]">
                            Te pido una sola cosa: si un dato claramente pertenece a la prueba de otra persona, no lo
                            borres ni lo modifiques. Creá lo tuyo y dejá lo demás como está.
                        </p>
                    </div>

                    <div className="rounded-md border border-border bg-surface p-6 lg:p-7">
                        <div className="micro">Próximo reinicio</div>
                        <DemoResetCountdown className="mt-2.5 text-[40px] font-semibold leading-[44px] tracking-[-0.04em]" />
                        <p className="mt-2.5 text-[14px] leading-[22px] text-muted">
                            Todos los días a las{' '}
                            <span className="tnum font-medium text-fg">00:00</span>, hora de Argentina (
                            <span className="tnum">America/Argentina/Buenos_Aires</span>), sin importar desde dónde
                            entres.
                        </p>
                        <div className="mt-4.5 border-t border-border pt-4">
                            <div className="text-[13px] leading-[21px] text-fg-body">En cada reinicio:</div>
                            <div className="mt-2 flex flex-col gap-1.5">
                                {RESET_ITEMS.map((item) => (
                                    <div key={item} className="flex gap-2 text-[13px] leading-[21px] text-fg-body">
                                        <CheckIcon className="mt-1 shrink-0" size={13} />
                                        <span>{item}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>

                {/* Usá información ficticia */}
                <div className="mt-8 rounded-md border border-pending-border bg-pending-block p-6 lg:p-7">
                    <div className="flex items-center gap-2">
                        <WarningIcon size={16} className="text-pending-fg" />
                        <span className="micro text-pending-fg">Usá información ficticia</span>
                    </div>
                    <h2 className="mt-2.5 text-[20px] font-semibold leading-[30px] tracking-[-0.02em] text-pending-strong sm:text-[22px]">
                        Todo lo que escribas acá es descartable y visible para cualquiera
                    </h2>
                    <div className="mt-4 grid max-w-[980px] grid-cols-1 gap-x-14 gap-y-1.5 sm:grid-cols-2">
                        {FICTICIOUS_DATA_POINTS.map((point, index) => (
                            <p key={index} className="text-[15px] leading-[25px] text-pending-strong">
                                {point}
                            </p>
                        ))}
                    </div>
                </div>

                {/* Dos recorridos sugeridos */}
                <div className="mt-16 lg:mt-20">
                    <h2 className="text-[24px] font-semibold leading-9 tracking-[-0.02em] sm:text-[28px]">
                        Dos recorridos sugeridos
                    </h2>
                    <p className="mt-2 max-w-[700px] text-[16px] leading-[26px] text-muted">
                        Abrí uno en una ventana normal y el otro en incógnito: vas a ver la agenda del negocio
                        actualizarse sola mientras reservás como cliente.
                    </p>

                    <div className="mt-6.5 grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <JourneyCard
                            label="Recorrido A"
                            title="Del lado del negocio"
                            steps={STAFF_STEPS}
                            note={
                                <div className="mt-4 rounded border border-border bg-surface-inset px-4 py-3.5">
                                    <div className="micro">Cuenta de demostración</div>
                                    <div className="mt-1.5 flex flex-wrap items-baseline gap-2.5">
                                        <span className="tnum text-[14px] font-medium">owner@reservahub.test</span>
                                        <span className="text-[13px] text-muted">·</span>
                                        <span className="tnum text-[14px] font-medium">{demoPassword}</span>
                                    </div>
                                    <div className="mt-1 text-[12px] leading-[18px] text-muted">
                                        Cuenta ficticia, creada por el seeder. No es de nadie.
                                    </div>
                                </div>
                            }
                        />

                        <JourneyCard
                            label="Recorrido B"
                            title="Del lado del cliente"
                            steps={CUSTOMER_STEPS}
                            note={
                                <div className="mt-4 rounded border border-border bg-surface-inset px-4 py-3.5">
                                    <div className="micro">Necesitás una cuenta</div>
                                    <div className="mt-1.5 text-[13px] leading-5 text-fg-body">
                                        Creála con datos inventados y una contraseña descartable. Se borra en el
                                        próximo reinicio.
                                    </div>
                                </div>
                            }
                        />
                    </div>
                </div>

                {/* Los emails de la demo */}
                <div className="mt-16 lg:mt-20">
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_460px] lg:items-start">
                        <div>
                            <h2 className="text-[24px] font-semibold leading-9 tracking-[-0.02em] sm:text-[28px]">
                                Los emails de la demo
                            </h2>
                            <p className="mt-2 max-w-[640px] text-[16px] leading-[26px] text-muted">
                                Confirmaciones, reprogramaciones, cancelaciones, recordatorios e invitaciones de
                                personal salen por email de verdad. Podés abrir el buzón de la demo y ver el mensaje
                                que generó tu acción.
                            </p>

                            <div className="mt-5.5 flex max-w-[640px] flex-col gap-3">
                                {MAIL_STEPS.map((description, index) => (
                                    <div key={description} className="flex gap-3.5">
                                        <span className="tnum w-[22px] shrink-0 pt-0.5 text-[13px] font-semibold text-muted">
                                            {String(index + 1).padStart(2, '0')}
                                        </span>
                                        <div className="text-[15px] leading-6">{description}</div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="rounded-md border border-border bg-surface p-6">
                            <div className="micro">El buzón también es compartido</div>
                            <p className="mt-2.5 text-[15px] leading-6 text-fg-body">
                                Puede haber mensajes generados por otras personas. Si un correo no corresponde a tu
                                prueba, te pido que lo ignores para no interrumpir la experiencia de otro visitante.
                            </p>
                            <p className="mt-2.5 text-[15px] leading-6 text-fg-body">
                                Por eso mismo: no uses tu dirección real. Cualquier visitante puede leer lo que
                                llegue ahí.
                            </p>

                            {mailUrl && (
                                <>
                                    <Button
                                        as="a"
                                        href={mailUrl}
                                        target="_blank"
                                        rel="noopener"
                                        variant="primary"
                                        size="lg"
                                        className="mt-4.5 w-full gap-2"
                                    >
                                        <MailIcon size={16} />
                                        Ver los emails de la demo
                                        <ExternalIcon size={14} />
                                    </Button>
                                    <p className="mt-2.5 text-[12px] leading-[18px] text-muted">
                                        Se abre en una pestaña nueva. Si el buzón no está disponible, el resto de la
                                        demo sigue funcionando igual.
                                    </p>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <div className="h-16 lg:h-18" />
        </PublicLayout>
    );
}
