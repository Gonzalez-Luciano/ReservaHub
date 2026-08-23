import { useRef } from 'react';
import Modal from './Modal';
import Button from './Button';

export default function ConfirmDialog({ open, onCancel, onConfirm, title, description, confirmLabel, tone = 'danger' }) {
    // El foco arranca en Cancelar, nunca en la acción destructiva.
    const cancelRef = useRef(null);

    return (
        <Modal open={open} onClose={onCancel} title={title} initialFocusRef={cancelRef}>
            <p className="text-[15px] leading-6 text-fg-body">{description}</p>
            <div className="mt-5 flex justify-end gap-2">
                <Button ref={cancelRef} data-autofocus variant="secondary" onClick={onCancel}>Cancelar</Button>
                <Button variant={tone} onClick={onConfirm}>{confirmLabel}</Button>
            </div>
        </Modal>
    );
}
