import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import IconButton from './ui/IconButton';
import Drawer from './ui/Drawer';
import {
    GridIcon,
    CalendarIcon,
    ServiceIcon,
    PeopleIcon,
    HolidayIcon,
    SettingsIcon,
    MenuIcon,
    MailIcon,
    ChevronLeftIcon,
    ArrowRightIcon,
} from './ui/icons';

const SIDEBAR_STORAGE_KEY = 'reservahub.sidebar';

const NAV_ITEMS = [
    { href: '/dashboard', label: 'Panel', icon: GridIcon, roles: ['owner', 'admin', 'employee'], exact: true },
    { href: '/dashboard/bookings', label: 'Reservas', icon: CalendarIcon, roles: ['owner', 'admin', 'employee'] },
    { href: '/dashboard/services', label: 'Servicios', icon: ServiceIcon, roles: ['owner', 'admin', 'employee'] },
    { href: '/dashboard/employees', label: 'Personal', icon: PeopleIcon, roles: ['owner', 'admin'] },
    { href: '/dashboard/holidays', label: 'Feriados', icon: HolidayIcon, roles: ['owner', 'admin'] },
    { href: '/dashboard/settings', label: 'Configuración', icon: SettingsIcon, roles: ['owner', 'admin'] },
];

function readStoredCollapsed() {
    try {
        return window.localStorage.getItem(SIDEBAR_STORAGE_KEY) === 'collapsed';
    } catch {
        return false;
    }
}

function writeStoredCollapsed(collapsed) {
    try {
        window.localStorage.setItem(SIDEBAR_STORAGE_KEY, collapsed ? 'collapsed' : 'expanded');
    } catch {
        // El acceso a localStorage puede lanzar (modo privado, cuota, etc.):
        // perder la preferencia no es crítico.
    }
}

function initials(name) {
    if (!name) return '';
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

function NavLinks({ items, currentUrl, collapsed, onNavigate }) {
    return (
        <nav aria-label="Secciones" className="flex flex-col gap-0.5 p-2.5">
            {items.map((item) => {
                const isActive = item.exact ? currentUrl === item.href : currentUrl.startsWith(item.href);
                const Icon = item.icon;
                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        onClick={onNavigate}
                        aria-current={isActive ? 'page' : undefined}
                        className={`flex items-center gap-2.5 rounded px-2.5 py-2 text-[13px] ${
                            isActive ? 'bg-chrome-active font-semibold text-fg' : 'text-fg hover:bg-chrome-active/60'
                        }`}
                    >
                        <Icon />
                        <span className={collapsed ? 'lg:hidden xl:inline' : ''}>{item.label}</span>
                    </Link>
                );
            })}
        </nav>
    );
}

function BusinessIdentity({ business, collapsed, onToggleCollapse }) {
    return (
        <div className="flex items-center justify-between gap-2 border-b border-border px-4 py-4">
            <div className={`min-w-0 ${collapsed ? 'lg:hidden xl:block' : ''}`}>
                <div className="truncate text-[15px] font-semibold tracking-[-0.01em]">
                    {business?.name ?? 'ReservaHub'}
                </div>
                <div className="mt-0.5 text-[12px] text-muted">Negocio activo</div>
            </div>
            {onToggleCollapse && (
                <div className="hidden shrink-0 lg:block xl:hidden">
                    <IconButton
                        label={collapsed ? 'Expandir barra lateral' : 'Colapsar barra lateral'}
                        icon={ChevronLeftIcon}
                        onClick={onToggleCollapse}
                        className={collapsed ? '[&_svg]:rotate-180' : ''}
                    />
                </div>
            )}
        </div>
    );
}

function UserFooter({ user, collapsed }) {
    return (
        <div className="flex items-center gap-2.5 border-t border-border px-4 py-3.5">
            <Link href="/account" className="flex min-w-0 flex-1 items-center gap-2.5">
                <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded bg-fg text-[12px] font-semibold text-bg">
                    {initials(user?.name)}
                </div>
                <div className={`min-w-0 ${collapsed ? 'lg:hidden xl:block' : ''}`}>
                    <div className="truncate text-[13px] font-medium">{user?.name}</div>
                    <div className="truncate text-[12px] text-muted">{user?.email}</div>
                </div>
            </Link>
            <IconButton
                label="Cerrar sesión"
                icon={ArrowRightIcon}
                onClick={() => router.post('/logout')}
                className="shrink-0"
            />
        </div>
    );
}

export default function DashboardLayout({ children }) {
    const page = usePage();
    const { auth } = page.props;
    const currentUrl = page.url ?? '/dashboard';
    const role = auth?.user?.role;
    const items = NAV_ITEMS.filter((item) => item.roles.includes(role));

    const [collapsed, setCollapsed] = useState(false);
    const [drawerOpen, setDrawerOpen] = useState(false);

    useEffect(() => {
        setCollapsed(readStoredCollapsed());
    }, []);

    const toggleCollapsed = () => {
        setCollapsed((prev) => {
            const next = !prev;
            writeStoredCollapsed(next);
            return next;
        });
    };

    const asideWidth = collapsed ? 'md:w-60 lg:w-16 xl:w-60' : 'md:w-60';

    return (
        <div className="flex min-h-screen bg-bg">
            <aside className={`sticky top-0 hidden h-screen shrink-0 flex-col overflow-y-auto border-r border-border bg-chrome md:flex ${asideWidth}`}>
                <BusinessIdentity business={auth?.business} collapsed={collapsed} onToggleCollapse={toggleCollapsed} />
                <NavLinks items={items} currentUrl={currentUrl} collapsed={collapsed} />
                <div className="flex-grow" />
                <UserFooter user={auth?.user} collapsed={collapsed} />
            </aside>

            <div className="flex min-h-screen min-w-0 flex-1 flex-col">
                <div className="flex items-center gap-3 border-b border-border bg-surface px-4 py-3 md:hidden">
                    <IconButton
                        label="Abrir navegación"
                        icon={MenuIcon}
                        onClick={() => setDrawerOpen(true)}
                    />
                    <span className="truncate text-[14px] font-semibold">{auth?.business?.name ?? 'ReservaHub'}</span>
                </div>

                <main className="flex-1 p-5 sm:p-6 lg:p-8">{children}</main>

                <footer className="mt-auto flex flex-wrap items-center gap-3 border-t border-border px-5 py-4 text-[12px] text-muted sm:px-6 lg:px-8">
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

            <Drawer open={drawerOpen} onClose={() => setDrawerOpen(false)} title={auth?.business?.name ?? 'Navegación'}>
                <div className="-mx-5 -my-4 flex flex-col">
                    <NavLinks items={items} currentUrl={currentUrl} collapsed={false} onNavigate={() => setDrawerOpen(false)} />
                    <div className="border-t border-border" />
                    <UserFooter user={auth?.user} collapsed={false} />
                </div>
            </Drawer>
        </div>
    );
}
