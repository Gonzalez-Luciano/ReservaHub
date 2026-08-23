import InputError from '../InputError';

export function Input({ className = '', ...props }) {
    return <input className={`block h-[42px] w-full rounded border border-border bg-surface px-3 text-[15px] placeholder:text-fg-placeholder ${className}`} {...props} />;
}

export function Select({ className = '', children, ...props }) {
    return <select className={`block h-[42px] w-full rounded border border-border bg-surface px-3 text-[15px] ${className}`} {...props}>{children}</select>;
}

export function Textarea({ className = '', ...props }) {
    return <textarea className={`block w-full rounded border border-border bg-surface px-3 py-2 text-[15px] ${className}`} {...props} />;
}

export function FormField({ id, label, error, hint, children }) {
    const hintId = hint ? `${id}-hint` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;

    return (
        <div>
            <label htmlFor={id} className="mb-1.5 block text-[13px] font-medium">{label}</label>
            {children({ id, 'aria-describedby': describedBy, 'aria-invalid': error ? true : undefined })}
            {hint && <p id={hintId} className="mt-1.5 text-xs leading-[18px] text-muted">{hint}</p>}
            <InputError id={errorId} message={error} />
        </div>
    );
}
