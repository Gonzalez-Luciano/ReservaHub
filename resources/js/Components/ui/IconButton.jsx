export default function IconButton({ label, icon: Icon, className = '', ...props }) {
    if (!label) {
        throw new Error('IconButton requiere `label`: es su nombre accesible.');
    }
    return (
        <button
            type="button"
            aria-label={label}
            className={`inline-flex h-11 w-11 items-center justify-center rounded text-muted ${className}`}
            {...props}
        >
            <Icon />
        </button>
    );
}
