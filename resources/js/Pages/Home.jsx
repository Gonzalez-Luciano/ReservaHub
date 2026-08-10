import { Link, usePage } from '@inertiajs/react';

export default function Home() {
    const { auth } = usePage().props;

    return (
        <div className="min-h-screen flex flex-col items-center justify-center gap-4">
            <h1 className="text-3xl font-bold">ReservaHub</h1>
            {auth?.user?.role === 'customer' && (
                <Link href="/mis-reservas" className="text-sm underline">Ver mis reservas</Link>
            )}
        </div>
    );
}
