import { useEffect, useId, useRef } from 'react';
import IconButton from './IconButton';
import { CrossIcon } from './icons';

export default function Modal({ open, onClose, title, initialFocusRef, children }) {
    const ref = useRef(null);
    const returnFocusTo = useRef(null);
    const titleId = useId();

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;

        if (open && !dialog.open) {
            returnFocusTo.current = document.activeElement;
            dialog.showModal();
            (initialFocusRef?.current ?? dialog.querySelector('[data-autofocus]') ?? dialog).focus();
        }

        if (!open && dialog.open) {
            dialog.close();
        }
    }, [open, initialFocusRef]);

    // `cancel` cubre Escape; `close` cubre cualquier cierre. El foco vuelve
    // siempre al elemento que abrió el diálogo.
    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;

        const handleClose = () => {
            onClose();
            returnFocusTo.current?.focus?.();
        };

        dialog.addEventListener('close', handleClose);
        return () => dialog.removeEventListener('close', handleClose);
    }, [onClose]);

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            className="w-full max-w-lg rounded-md border border-border bg-surface p-0 text-fg shadow-[0_16px_48px_rgba(25,26,23,.18)] backdrop:bg-fg/30"
        >
            <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                <h2 id={titleId} className="text-[17px] font-semibold tracking-[-0.015em]">{title}</h2>
                <IconButton label="Cerrar" icon={CrossIcon} onClick={() => ref.current?.close()} className="-mr-3 -mt-2" />
            </div>
            <div className="px-5 py-4">{children}</div>
        </dialog>
    );
}
