import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

const COALESCE_MS = 250;

/**
 * Único mecanismo de suscripción en tiempo real de la aplicación.
 *
 * El evento es una pista de invalidación, no datos: la respuesta es recargar
 * el estado canónico por Inertia, donde las Policies y el controlador siguen
 * siendo la autoridad sobre qué ve cada usuario.
 *
 * `useEcho` se ocupa de suscribir, desuscribir, limpiar al desmontar y
 * deduplicar el doble montaje de StrictMode; su callback queda memoizado con
 * lista de dependencias vacía, así que no hay que pasarle valores cambiantes.
 * El timer, en cambio, lo crea este componente y este componente lo cancela.
 */
export default function BookingsRealtime({ businessId, only }) {
    const timer = useRef(null);

    useEcho(`business.${businessId}`, '.booking.changed', () => {
        // Una corrida de bookings:expire-unpaid puede cancelar varias reservas
        // en milisegundos. Sin esto serían varias recargas seguidas.
        clearTimeout(timer.current);
        timer.current = setTimeout(() => {
            router.reload({ only, preserveState: true, preserveScroll: true });
        }, COALESCE_MS);
    });

    // Sin esto, navegar dentro de la ventana de 250 ms dispararía una recarga
    // sobre una página que ya no está montada.
    useEffect(() => () => clearTimeout(timer.current), []);

    return null;
}
