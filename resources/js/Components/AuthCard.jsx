export default function AuthCard({ title, children }) {
    return (
        <div className="min-h-screen flex items-center justify-center bg-gray-50 px-4">
            <div className="w-full max-w-sm rounded-lg bg-white p-8 shadow">
                <h1 className="mb-6 text-2xl font-bold text-gray-900">{title}</h1>
                {children}
            </div>
        </div>
    );
}
