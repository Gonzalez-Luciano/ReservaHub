import { Link } from '@inertiajs/react';
import DemoResetCountdown from './domain/DemoResetCountdown';
import { ArrowRightIcon } from './ui/icons';

// Franja informativa, neutra a propósito: es información sobre cómo
// funciona la demo, no un estado de advertencia. Nunca ámbar.
export default function DemoStrip() {
    return (
        <div className="grid grid-cols-1 overflow-hidden rounded-md border border-border bg-surface sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1fr_216px]">
            <div className="border-b border-border p-4 sm:border-r xl:border-b-0">
                <div className="micro">Demo pública compartida</div>
                <p className="mt-1.5 text-[13px] leading-5 text-fg-body">
                    Puede haber otras personas usándola al mismo tiempo. Vas a ver reservas y usuarios que no
                    son tuyos.
                </p>
            </div>

            <div className="border-b border-border p-4 xl:border-b-0 xl:border-l">
                <div className="micro">Se restaura cada lunes</div>
                <p className="mt-1.5 text-[13px] leading-5 text-fg-body">
                    Próximo reinicio completo en <span className="font-semibold"><DemoResetCountdown /></span>
                    <br />
                    <span className="text-muted">Lunes 00:00, hora de Argentina</span>
                </p>
            </div>

            <div className="border-b border-border p-4 sm:border-r sm:border-b-0 xl:border-b-0 xl:border-l xl:border-r-0">
                <div className="micro">Usá datos ficticios</div>
                <p className="mt-1.5 text-[13px] leading-5 text-fg-body">
                    Nada personal, ninguna contraseña que uses en otro lado, ningún dato financiero.
                </p>
            </div>

            <div className="flex items-center p-4 xl:border-l">
                <Link
                    href="/como-funciona"
                    className="inline-flex items-center gap-1.5 text-[13px] font-medium hover:text-muted"
                >
                    Cómo funciona la demo
                    <ArrowRightIcon size={14} />
                </Link>
            </div>
        </div>
    );
}
