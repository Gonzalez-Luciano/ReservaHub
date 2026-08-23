import { useEffect } from 'react';

export default function Toast({ message, onDismiss, duration = 4000 }) {
    useEffect(() => {
        if (!message) return;
        const timer = setTimeout(onDismiss, duration);
        return () => clearTimeout(timer);
    }, [message, onDismiss, duration]);

    if (!message) return null;

    return (
        <div
            role="status"
            className="fixed bottom-5 left-5 z-50 rounded-md border border-border bg-surface px-4 py-3 text-[14px] shadow-[0_12px_32px_rgba(25,26,23,.16)]"
        >
            {message}
        </div>
    );
}
