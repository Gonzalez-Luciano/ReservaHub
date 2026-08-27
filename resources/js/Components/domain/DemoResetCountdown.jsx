import { useEffect, useState } from 'react';

const DEMO_TIMEZONE = 'America/Argentina/Buenos_Aires';
const REFRESH_MS = 30_000;
const WEEK_SECONDS = 7 * 86_400;

// El horario del reinicio vive acá y en la programación real que corre en el
// servidor (routes/console.php, `demo:reset` los lunes 00:00). Si cambia una,
// hay que cambiar la otra.
//
// Días transcurridos desde el lunes. `en-GB` con weekday:'short' devuelve
// exactamente estas tres letras.
const DAYS_SINCE_MONDAY = { Mon: 0, Tue: 1, Wed: 2, Thu: 3, Fri: 4, Sat: 5, Sun: 6 };

function secondsUntilReset() {
    // formatToParts, nunca format(): parsear una cadena formateada por locale
    // es frágil y algunos locales representan la medianoche como 24:00.
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: DEMO_TIMEZONE,
        hourCycle: 'h23',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).formatToParts(new Date());

    const read = (type) => Number(parts.find((part) => part.type === type)?.value ?? 0);
    const weekday = parts.find((part) => part.type === 'weekday')?.value;

    const elapsedToday = read('hour') * 3600 + read('minute') * 60 + read('second');
    const elapsedThisWeek = (DAYS_SINCE_MONDAY[weekday] ?? 0) * 86_400 + elapsedToday;

    return elapsedThisWeek === 0 ? 0 : WEEK_SECONDS - elapsedThisWeek;
}

function format(seconds) {
    // Por exceso: nunca mostrar "0 h 0 min" con segundos todavía por delante.
    const minutes = Math.ceil(seconds / 60);
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);

    // Con una semana por delante, "167 h 59 min" no se lee. Por encima de un
    // día la unidad chica es la hora; el último día vuelve a los minutos.
    return days > 0 ? `${days} d ${hours} h` : `${hours} h ${minutes % 60} min`;
}

export default function DemoResetCountdown({ className = '' }) {
    const [seconds, setSeconds] = useState(secondsUntilReset);

    useEffect(() => {
        const timer = setInterval(() => setSeconds(secondsUntilReset()), REFRESH_MS);
        return () => clearInterval(timer);
    }, []);

    // Sin aria-live: no interrumpe a lectores de pantalla cada minuto.
    // min-w cubre la cadena más larga de las dos formas ("23 h 59 min", 11ch)
    // para que la franja no se reacomode al cruzar el último día.
    return <span className={`tnum inline-block min-w-[11ch] ${className}`}>{format(seconds)}</span>;
}
