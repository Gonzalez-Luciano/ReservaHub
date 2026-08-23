export default function TableShell({ className = '', children }) {
    return <div className={`overflow-hidden rounded-md border border-border bg-surface [&>*+*]:border-t [&>*+*]:border-border ${className}`}>{children}</div>;
}
