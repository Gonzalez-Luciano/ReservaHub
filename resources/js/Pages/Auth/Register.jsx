import { Link, useForm } from '@inertiajs/react';
import AuthCard from '../../Components/AuthCard';
import Button from '../../Components/ui/Button';
import Alert from '../../Components/ui/Alert';
import InputError from '../../Components/InputError';
import { Input, FormField } from '../../Components/ui/Field';

function AccountTypeOption({ label, hint, checked, onChange }) {
    return (
        <label className={`flex cursor-pointer flex-col rounded-md border bg-surface p-3.5 ${checked ? 'border-fg' : 'border-border'}`}>
            <span className="flex items-center gap-2.5">
                <input type="radio" name="account_type" checked={checked} onChange={onChange} className="h-4 w-4 accent-fg" />
                <span className="text-[15px] font-medium">{label}</span>
            </span>
            <span className="mt-1.5 ml-[26px] text-xs leading-[18px] text-muted">{hint}</span>
        </label>
    );
}

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        account_type: 'business',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        business_name: '',
    });

    function submit(e) {
        e.preventDefault();
        post('/register', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    }

    return (
        <AuthCard title="Crear cuenta">
            <p className="-mt-2 mb-5 text-[15px] leading-6 text-muted">
                Elegí desde qué lado querés recorrer ReservaHub.
            </p>

            <Alert tone="warning" title="Datos ficticios">
                Es una demo pública y compartida. Usá un nombre y un correo inventados, y una contraseña
                descartable que <strong>no uses en ningún otro servicio</strong>. Todo esto se borra en el
                próximo reinicio diario.
            </Alert>

            <form onSubmit={submit} className="mt-5 flex flex-col gap-5">
                <div>
                    <div className="mb-2 text-[13px] font-medium">Tipo de cuenta</div>
                    <div role="radiogroup" aria-label="Tipo de cuenta" className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                        <AccountTypeOption
                            label="Tengo un negocio"
                            hint="Panel, servicios, personal y agenda"
                            checked={data.account_type === 'business'}
                            onChange={() => setData('account_type', 'business')}
                        />
                        <AccountTypeOption
                            label="Quiero reservar turnos"
                            hint="Buscar un negocio y sacar un turno"
                            checked={data.account_type === 'customer'}
                            onChange={() => setData('account_type', 'customer')}
                        />
                    </div>
                    <InputError message={errors.account_type} />
                </div>

                {data.account_type === 'business' && (
                    <FormField id="business_name" label="Nombre del negocio" error={errors.business_name}>
                        {(fieldProps) => (
                            <Input
                                {...fieldProps}
                                value={data.business_name}
                                onChange={(e) => setData('business_name', e.target.value)}
                            />
                        )}
                    </FormField>
                )}

                <FormField id="name" label="Tu nombre" error={errors.name}>
                    {(fieldProps) => (
                        <Input
                            {...fieldProps}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            autoFocus
                        />
                    )}
                </FormField>

                <FormField
                    id="email"
                    label="Correo electrónico"
                    error={errors.email}
                    hint="Los correos de la demo llegan a un buzón compartido que cualquiera puede abrir."
                >
                    {(fieldProps) => (
                        <Input
                            {...fieldProps}
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
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

                <FormField id="password_confirmation" label="Repetir contraseña" error={errors.password_confirmation}>
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

                <p className="text-center text-[14px] text-muted">
                    ¿Ya tenés cuenta? <Link href="/login" className="font-medium text-fg hover:text-fg">Iniciar sesión</Link>
                </p>
            </form>
        </AuthCard>
    );
}
