import { Link } from '@inertiajs/react';
import PublicLayout from '../../../Components/PublicLayout';

export default function Index({ businesses }) {
    return (
        <PublicLayout>
            <div className="p-8">
                <h1 className="mb-6 text-2xl font-bold">Negocios</h1>
                {businesses.length === 0 ? (
                    <p className="text-sm text-gray-500">Todavía no hay negocios disponibles.</p>
                ) : (
                    <ul className="space-y-4">
                        {businesses.map((business) => (
                            <li key={business.id} className="rounded-md border bg-white p-4">
                                <div className="flex items-center justify-between">
                                    <p className="font-semibold">{business.name}</p>
                                    <Link
                                        href={`/negocios/${business.slug}`}
                                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                                    >
                                        Ver servicios
                                    </Link>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
