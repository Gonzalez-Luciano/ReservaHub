import AuthCard from '../../Components/AuthCard';

export default function Unavailable() {
    return (
        <AuthCard title="Invitación no disponible">
            <p className="text-[15px] leading-6 text-muted">
                Esta invitación ya no está disponible. Puede haber sido revocada o haber vencido.
            </p>
            <p className="mt-4 text-[15px] leading-6 text-muted">
                Contactá al dueño del negocio que te invitó para que te envíe una nueva.
            </p>
        </AuthCard>
    );
}
