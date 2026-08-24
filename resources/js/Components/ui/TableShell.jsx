// El scroll horizontal vive en este wrapper exterior (no en el interior con
// el borde redondeado): así el radio sigue recortando el fondo mientras el
// contenido angosto para una grilla ancha (p.ej. la tabla de servicios en
// tablet) queda alcanzable con swipe/trackpad aunque no quepa entero.
export default function TableShell({ className = '', children }) {
    return (
        <div className={`overflow-x-auto rounded-md border border-border bg-surface ${className}`}>
            <div className="[&>*+*]:border-t [&>*+*]:border-border">{children}</div>
        </div>
    );
}
