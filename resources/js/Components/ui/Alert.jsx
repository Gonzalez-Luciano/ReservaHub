import { WarningIcon } from './icons';

export default function Alert({ tone = 'warning', title, children }) {
    const skin = tone === 'warning'
        ? 'border-pending-border bg-pending-block text-pending-strong'
        : 'border-border bg-surface text-fg-body';
    return (
        <div className={`rounded-md border p-4 ${skin}`}>
            {title && (
                <div className="flex items-center gap-2">
                    <WarningIcon size={15} className={tone === 'warning' ? 'text-pending-fg' : 'text-muted'} />
                    <span className="micro text-pending-fg">{title}</span>
                </div>
            )}
            <div className="mt-1.5 text-[14px] leading-[21px]">{children}</div>
        </div>
    );
}
