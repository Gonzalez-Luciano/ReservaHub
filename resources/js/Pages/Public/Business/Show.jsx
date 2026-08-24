import PublicLayout from '../../../Components/PublicLayout';
import PageHeader from '../../../Components/ui/PageHeader';
import EmptyState from '../../../Components/ui/EmptyState';
import ServiceCard from '../../../Components/domain/ServiceCard';
import { ServiceIcon } from '../../../Components/ui/icons';

export default function Show({ business, services }) {
    return (
        <PublicLayout>
            <div className="mx-auto max-w-[1440px] px-6 py-10 lg:px-10 lg:py-14">
                <PageHeader title={business.name} subtitle="Elegí un servicio para reservar un turno." />

                {services.length === 0 ? (
                    <EmptyState
                        icon={ServiceIcon}
                        title="Todavía no hay servicios publicados"
                        description="Volvé a intentarlo más tarde."
                    />
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {services.map((service) => (
                            <ServiceCard
                                key={service.id}
                                service={service}
                                currency={business.currency}
                                href={`/negocios/${business.slug}/reservar?service_id=${service.id}`}
                            />
                        ))}
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}
