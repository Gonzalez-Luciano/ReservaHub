import { useForm } from '@inertiajs/react';
import AuthCard from '../../Components/AuthCard';
import Button from '../../Components/ui/Button';
import { Input, FormField } from '../../Components/ui/Field';

export default function Accept({ token, email, businessName }) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        name: '',
        password: '',
        password_confirmation: '',
    });

    function submit(e) {
        e.preventDefault();
        post(`/invitations/${token}/accept`);
    }

    return (
        <AuthCard title={`Unite a ${businessName}`}>
            <p className="-mt-2 mb-5 text-[15px] leading-6 text-muted">Invitación para {email}</p>

            <form onSubmit={submit} className="flex flex-col gap-5">
                <FormField id="name" label="Nombre" error={errors.name}>
                    {(fieldProps) => (
                        <Input
                            {...fieldProps}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            autoFocus
                        />
                    )}
                </FormField>

                <FormField id="password" label="Contraseña" error={errors.password}>
                    {(fieldProps) => (
                        <Input
                            {...fieldProps}
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                        />
                    )}
                </FormField>

                <FormField id="password_confirmation" label="Confirmar contraseña" error={errors.password_confirmation}>
                    {(fieldProps) => (
                        <Input
                            {...fieldProps}
                            type="password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                        />
                    )}
                </FormField>

                <Button type="submit" variant="primary" size="lg" disabled={processing} className="w-full">
                    Crear cuenta
                </Button>
            </form>
        </AuthCard>
    );
}
