import Modal from './Modal';

export default function Drawer({ open, onClose, title, children }) {
    return (
        <div className="[&>dialog]:m-0 [&>dialog]:h-full [&>dialog]:max-h-none [&>dialog]:max-w-[280px] [&>dialog]:rounded-none [&>dialog]:border-l-0">
            <Modal open={open} onClose={onClose} title={title}>{children}</Modal>
        </div>
    );
}
