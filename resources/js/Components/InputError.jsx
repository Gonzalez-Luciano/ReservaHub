export default function InputError({ id, message }) {
    if (!message) {
        return null;
    }

    return <p id={id} className="mt-1.5 text-[13px] text-noshow-fg">{message}</p>;
}
