import { Link, router, usePage } from '@inertiajs/react';

export default function PublicLayout({ children }) {
    const { auth } = usePage().props;

    return (
        <div className="min-h-screen bg-gray-50">
            {auth?.user && (
                <nav className="flex items-center gap-6 border-b bg-white px-6 py-3 text-sm font-medium text-gray-700">
                    <Link href="/mis-reservas" className="hover:text-gray-900">Mis reservas</Link>
                    <button onClick={() => router.post('/logout')} className="ml-auto hover:text-gray-900">
                        Salir
                    </button>
                </nav>
            )}
            <main>{children}</main>
        </div>
    );
}
