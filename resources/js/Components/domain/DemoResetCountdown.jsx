import { useEffect, useState } from 'react';

const DEMO_TIMEZONE = 'America/Argentina/Buenos_Aires';
const REFRESH_MS = 30_000;

// El horario del reinicio vive acá y en la programación real que corre en el
// servidor. Si cambia una, hay que cambiar la otra.
const RESET_HOUR = 0;

function secondsUntilReset() {
    // formatToParts, nunca format(): parsear una cadena formateada por locale
    // es frágil y algunos locales representan la medianoche como 24:00.
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: DEMO_TIMEZONE,
        hourCycle: 'h23',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).formatToParts(new Date());

    const read = (type) => Number(parts.find((part) => part.type === type)?.value ?? 0);

    const elapsed = read('hour') * 3600 + read('minute') * 60 + read('second');
    const target = RESET_HOUR * 3600;

    return elapsed <= target ? target - elapsed : 86_400 - elapsed + target;
}

function format(seconds) {
    // Por exceso: nunca mostrar "0 h 0 min" con segundos todavía por delante.
    const minutes = Math.ceil(seconds / 60);
    return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

export default function DemoResetCountdown({ className = '' }) {
    const [seconds, setSeconds] = useState(secondsUntilReset);

    useEffect(() => {
        const timer = setInterval(() => setSeconds(secondsUntilReset()), REFRESH_MS);
        return () => clearInterval(timer);
    }, []);

    // Sin aria-live: no interrumpe a lectores de pantalla cada minuto.
    // min-w evita que la franja se reacomode al pasar de "12 h 42 min" a "9 h 5 min".
    return <span className={`tnum inline-block min-w-[6.5ch] ${className}`}>{format(seconds)}</span>;
}
