import { Link } from '@inertiajs/react';
import { MailIcon } from './ui/icons';

export default function AuthCard({ title, children }) {
    return (
        <div className="flex min-h-screen flex-col bg-bg">
            <header className="border-b border-border">
                <div className="flex h-16 items-center gap-5 px-6 lg:px-10">
                    <Link href="/" className="flex items-baseline gap-3">
                        <span className="text-[17px] font-semibold tracking-[-0.02em]">ReservaHub</span>
                        <span className="micro">Demo pública</span>
                    </Link>
                    <nav aria-label="Principal" className="ml-auto flex items-center gap-5 text-[14px]">
                        <Link href="/como-funciona" className="hover:text-fg">Cómo funciona la demo</Link>
                        <Link href="/login" className="hover:text-fg">Ingresar</Link>
                    </nav>
                </div>
            </header>

            <main className="flex flex-1 justify-center px-6 py-11">
                <div className="w-full max-w-[460px]">
                    <h1 className="text-[28px] font-semibold leading-9 tracking-[-0.025em]">{title}</h1>
                    <div className="mt-5">{children}</div>
                </div>
            </main>

            <footer className="mt-auto flex flex-wrap items-center gap-3.5 border-t border-border px-6 py-4 text-[12px] text-muted lg:px-10">
                <span>ReservaHub · demo de portfolio · pagos y emails simulados</span>
                <span className="flex-grow" />
                <Link href="/como-funciona" className="hover:text-fg">Cómo funciona la demo</Link>
                <span className="h-3.5 w-px bg-border" aria-hidden="true" />
                <a
                    href="mailto:lucianogonzalez12004@gmail.com"
                    className="inline-flex items-center gap-1.5 font-medium text-fg"
                >
                    <MailIcon size={13} />
                    lucianogonzalez12004@gmail.com
                </a>
            </footer>
        </div>
    );
}
