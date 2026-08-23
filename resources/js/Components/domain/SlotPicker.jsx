export default function SlotPicker({ slots, value, onChange, columns = 6 }) {
    if (slots.length === 0) {
        return <p className="text-[13px] leading-5 text-muted">No hay horarios libres ese día.</p>;
    }

    return (
        <div role="radiogroup" aria-label="Horario disponible" className={`grid gap-2 grid-cols-3 sm:grid-cols-4 lg:grid-cols-${columns}`}>
            {slots.map((slot) => {
                const selected = value === slot.starts_at;
                const label = new Date(slot.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                return (
                    <button
                        key={slot.starts_at}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => onChange(slot.starts_at)}
                        className={`tnum flex h-12 items-center justify-center rounded border text-[15px] font-medium ${selected ? 'border-fg bg-fg text-bg' : 'border-border bg-surface'}`}
                    >
                        {label}
                    </button>
                );
            })}
        </div>
    );
}
