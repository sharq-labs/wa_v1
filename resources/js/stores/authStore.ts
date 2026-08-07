import { create } from 'zustand';
import type { User } from '@/types';

interface AuthState {
    user: User | null;
    initialized: boolean;
    workspaceId: number | null;
    setUser: (user: User | null) => void;
    setInitialized: (value: boolean) => void;
    setWorkspaceId: (id: number | null) => void;
}

export const useAuthStore = create<AuthState>((set) => ({
    user: null,
    initialized: false,
    workspaceId: null,
    setUser: (user) =>
        set((state) => ({
            user,
            workspaceId: user ? (state.workspaceId ?? user.current_workspace_id ?? user.workspaces?.[0]?.id ?? null) : null,
        })),
    setInitialized: (initialized) => set({ initialized }),
    setWorkspaceId: (workspaceId) => set({ workspaceId }),
}));

export function useWorkspaceId(): number {
    const id = useAuthStore((s) => s.workspaceId);
    if (!id) throw new Error('No active workspace');
    return id;
}
