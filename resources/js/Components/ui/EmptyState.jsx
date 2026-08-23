export default function EmptyState({ icon: Icon, title, description, action }) {
    return (
        <div className="rounded-md border border-border bg-surface px-6 py-7 text-center">
            {Icon && <Icon size={22} className="mx-auto text-muted opacity-45" />}
            <div className="mt-2 text-[15px] font-medium">{title}</div>
            {description && <p className="mx-auto mt-1 max-w-[280px] text-[13px] leading-5 text-muted">{description}</p>}
            {action && <div className="mt-4 flex justify-center">{action}</div>}
        </div>
    );
}
