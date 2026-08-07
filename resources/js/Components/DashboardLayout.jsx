import { Link, router } from '@inertiajs/react';

export default function DashboardLayout({ children }) {
    return (
        <div className="min-h-screen bg-gray-50">
            <nav className="flex items-center gap-6 border-b bg-white px-6 py-3 text-sm font-medium text-gray-700">
                <Link href="/dashboard" className="hover:text-gray-900">Panel</Link>
                <Link href="/dashboard/services" className="hover:text-gray-900">Servicios</Link>
                <Link href="/dashboard/employees" className="hover:text-gray-900">Empleados</Link>
                <button onClick={() => router.post('/logout')} className="ml-auto hover:text-gray-900">
                    Salir
                </button>
            </nav>
            <main>{children}</main>
        </div>
    );
}
