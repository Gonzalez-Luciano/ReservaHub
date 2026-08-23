export default function Surface({ as: Tag = 'div', className = '', children, ...props }) {
    return <Tag className={`rounded-md border border-border bg-surface ${className}`} {...props}>{children}</Tag>;
}
