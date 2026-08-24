import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import Button from './ui/Button';
import IconButton from './ui/IconButton';
import Drawer from './ui/Drawer';
import { MailIcon, MenuIcon } from './ui/icons';

function HeaderNav({ user, className = '', onNavigate }) {
    return (
        <nav aria-label="Principal" className={`items-center gap-5 text-[14px] ${className}`}>
            <Link href="/negocios" className="hover:text-fg" onClick={onNavigate}>Negocios</Link>
            <Link href="/como-funciona" className="hover:text-fg" onClick={onNavigate}>Cómo funciona la demo</Link>
            {user ? (
                <>
                    <Link href="/mis-reservas" className="hover:text-fg" onClick={onNavigate}>Mis reservas</Link>
                    <Link href="/account" className="hover:text-fg" onClick={onNavigate}>{user.name}</Link>
                    <Button variant="secondary" onClick={() => router.post('/logout')}>Salir</Button>
                </>
            ) : (
                <>
                    <Link href="/login" className="hover:text-fg" onClick={onNavigate}>Ingresar</Link>
                    <Button href="/register" variant="primary" onClick={onNavigate}>Crear cuenta</Button>
                </>
            )}
        </nav>
    );
}

export default function PublicLayout({ children }) {
    const { auth } = usePage().props;
    const [navOpen, setNavOpen] = useState(false);

    return (
        <div className="flex min-h-screen flex-col bg-bg">
            <header className="border-b border-border">
                <div className="mx-auto flex h-16 max-w-[1440px] items-center gap-5 px-6 lg:px-10">
                    <Link href="/" className="flex items-baseline gap-3">
                        <span className="text-[17px] font-semibold tracking-[-0.02em]">ReservaHub</span>
                        <span className="micro">Demo pública</span>
                    </Link>
                    <HeaderNav user={auth?.user} className="ml-auto hidden lg:flex" />
                    <IconButton
                        label="Abrir navegación"
                        icon={MenuIcon}
                        onClick={() => setNavOpen(true)}
                        className="ml-auto lg:hidden"
                    />
                </div>
            </header>

            <Drawer open={navOpen} onClose={() => setNavOpen(false)} title="Navegación">
                <HeaderNav user={auth?.user} className="flex flex-col items-start gap-4" onNavigate={() => setNavOpen(false)} />
            </Drawer>

            <main className="flex-1">{children}</main>

            <footer className="mt-auto border-t border-border">
                <div className="mx-auto flex flex-col gap-10 px-6 py-10 lg:flex-row lg:items-start lg:gap-16 lg:px-10">
                    <div className="max-w-[300px]">
                        <div className="text-[16px] font-semibold tracking-[-0.015em]">ReservaHub</div>
                        <p className="mt-1.5 text-[13px] leading-[21px] text-muted">
                            Sistema de reservas por franjas horarias. Proyecto de portfolio con pagos y emails
                            simulados.
                        </p>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <div className="micro mb-0.5">La demo</div>
                        <Link href="/negocios" className="text-[13px] hover:text-fg">Ver negocios</Link>
                        <Link href="/como-funciona" className="text-[13px] hover:text-fg">Cómo funciona la demo</Link>
                        <Link href="/login" className="text-[13px] hover:text-fg">Ingresar</Link>
                    </div>

                    <div className="lg:flex-grow" />

                    <div className="max-w-[360px]">
                        <div className="micro">Contacto</div>
                        <div className="mt-1.5 text-[19px] font-semibold leading-[26px] tracking-[-0.015em]">
                            ¿Hablamos del proyecto?
                        </div>
                        <p className="mt-1 text-[13px] leading-[21px] text-muted">
                            Escribime si querés ver el código, preguntar cómo está construido o charlar de trabajo.
                        </p>
                        <a
                            href="mailto:lucianogonzalez12004@gmail.com"
                            className="mt-3 inline-flex h-[42px] items-center gap-2 rounded border border-fg bg-fg px-4 text-[14px] font-medium text-bg"
                        >
                            <MailIcon size={16} />
                            lucianogonzalez12004@gmail.com
                        </a>
                    </div>
                </div>

                <div className="flex items-center gap-4 border-t border-border px-6 py-4 lg:px-10">
                    <span className="text-[13px] text-muted">Hecho por Luciano González</span>
                    <span className="flex-grow" />
                    <span className="text-[13px] text-muted">No es un servicio comercial en funcionamiento.</span>
                </div>
            </footer>
        </div>
    );
}
