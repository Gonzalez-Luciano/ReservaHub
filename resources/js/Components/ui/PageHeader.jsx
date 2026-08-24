export default function PageHeader({ title, subtitle, actions }) {
    return (
        <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
            <div>
                <h1 className="text-2xl font-semibold leading-8 tracking-[-0.02em]">{title}</h1>
                {subtitle && <p className="mt-0.5 text-[13px] leading-5 text-muted">{subtitle}</p>}
            </div>
            {actions && <div className="flex items-center gap-2">{actions}</div>}
        </div>
    );
}
