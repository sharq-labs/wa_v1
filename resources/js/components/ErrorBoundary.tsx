import { Component, type ReactNode } from 'react';

interface Props {
    children: ReactNode;
}

interface State {
    error: Error | null;
}

/**
 * Catches render errors so a broken page never blanks the whole app.
 */
export default class ErrorBoundary extends Component<Props, State> {
    state: State = { error: null };

    static getDerivedStateFromError(error: Error): State {
        return { error };
    }

    render() {
        if (this.state.error) {
            return (
                <div className="flex h-full min-h-64 flex-col items-center justify-center gap-3 p-8 text-center">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-xl">⚠️</div>
                    <p className="text-sm font-semibold text-slate-800">Something went wrong on this page.</p>
                    <p className="max-w-md text-xs text-slate-500">{this.state.error.message}</p>
                    <button
                        onClick={() => {
                            this.setState({ error: null });
                            window.location.reload();
                        }}
                        className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                    >
                        Reload
                    </button>
                </div>
            );
        }

        return this.props.children;
    }
}
