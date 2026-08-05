export default function InputError({ message }) {
    if (!message) {
        return null;
    }

    return <p className="mt-1 text-sm text-red-600">{message}</p>;
}
