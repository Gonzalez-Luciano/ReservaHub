export default function StatCard({ label, value, hint, tone }) {
    const colour = tone === 'pending' ? 'text-pending-fg' : '';
    return (
        <div>
            <div className={`micro ${tone === 'pending' ? 'text-pending-fg' : ''}`}>{label}</div>
            <div className="mt-0.5 flex items-baseline gap-1.5">
                <span className={`tnum text-[26px] font-semibold leading-8 tracking-[-0.03em] ${colour}`}>{value}</span>
                {hint && <span className="text-[13px] text-muted">{hint}</span>}
            </div>
        </div>
    );
}
