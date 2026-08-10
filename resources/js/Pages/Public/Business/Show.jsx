import { Link } from '@inertiajs/react';

export default function Show({ business, services }) {
    return (
        <div className="min-h-screen bg-gray-50 p-8">
            <h1 className="mb-6 text-2xl font-bold">{business.name}</h1>
            <ul className="space-y-4">
                {services.map((service) => (
                    <li key={service.id} className="rounded-md border bg-white p-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="font-semibold">{service.name}</p>
                                <p className="text-sm text-gray-500">{service.description}</p>
                                <p className="text-sm text-gray-500">{service.duration_minutes} min — ${service.price}</p>
                            </div>
                            <Link
                                href={`/negocios/${business.slug}/reservar?service_id=${service.id}`}
                                className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                            >
                                Reservar
                            </Link>
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}
