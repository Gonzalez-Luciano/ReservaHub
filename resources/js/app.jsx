import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { configureEcho } from '@laravel/echo-react';

// Con solo el broadcaster alcanza: para 'reverb', configureEcho ya toma
// VITE_REVERB_APP_KEY, VITE_REVERB_HOST, VITE_REVERB_PORT y
// VITE_REVERB_SCHEME de import.meta.env, y fija enabledTransports en
// ['ws', 'wss'].
//
// La llamada es perezosa: guarda la configuración y no construye la instancia
// de Echo hasta la primera suscripción. Por eso no rompe nada cuando el bundle
// se compiló sin configuración de Reverb.
configureEcho({
    broadcaster: 'reverb',
});

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });
        return pages[`./Pages/${name}.jsx`];
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
