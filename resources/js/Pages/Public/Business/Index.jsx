import PublicLayout from '../../../Components/PublicLayout';
import PageHeader from '../../../Components/ui/PageHeader';
import Surface from '../../../Components/ui/Surface';
import Button from '../../../Components/ui/Button';
import EmptyState from '../../../Components/ui/EmptyState';
import { ServiceIcon } from '../../../Components/ui/icons';

function formatCurrency(value, currency) {
    return new Intl.NumberFormat('es-AR', { style: 'currency', currency }).format(value);
}

function BusinessCard({ business }) {
    const hasServices = business.services_count > 0;

    return (
        <Surface className="flex flex-col justify-between p-5">
            <div>
                <h3 className="text-[18px] font-semibold leading-6 tracking-[-0.01em]">{business.name}</h3>
                <p className="mt-1.5 text-[13px] leading-5 text-muted">
                    {hasServices
                        ? `${business.services_count} ${business.services_count === 1 ? 'servicio' : 'servicios'} disponibles`
                        : 'Todavía no publicó servicios'}
                </p>
                {hasServices && (
                    <p className="mt-3 text-[15px]">
                        <span className="text-muted">Desde </span>
                        <span className="tnum font-semibold">
                            {formatCurrency(business.lowest_price, business.currency)}
                        </span>
                    </p>
                )}
            </div>
            <Button href={`/negocios/${business.slug}`} variant="primary" className="mt-4 self-start">
                Ver servicios
            </Button>
        </Surface>
    );
}

export default function Index({ businesses }) {
    return (
        <PublicLayout>
            <div className="mx-auto max-w-[1440px] px-6 py-10 lg:px-10 lg:py-14">
                <PageHeader
                    title="Negocios"
                    subtitle="Elegí un negocio para ver sus servicios y reservar un turno."
                />

                {businesses.length === 0 ? (
                    <EmptyState
                        icon={ServiceIcon}
                        title="Todavía no hay negocios disponibles"
                        description="Volvé a intentarlo más tarde."
                    />
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {businesses.map((business) => (
                            <BusinessCard key={business.id} business={business} />
                        ))}
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}
