export default function Index({ business }) {
    return (
        <div className="p-8">
            <h1 className="text-2xl font-bold">Panel de {business.name}</h1>
            <p className="mt-2 text-sm text-gray-600">
                El dashboard real (reservas de hoy, ingresos, etc.) llega en una fase posterior.
            </p>
        </div>
    );
}
