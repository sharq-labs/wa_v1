import { api } from '@/lib/api';
import type { WhatsAppAccount } from '@/types';
import type { MetaEmbeddedSignupConfig } from '@/lib/metaEmbeddedSignup';

const workspaceBase = (workspaceId: number) => `/api/workspaces/${workspaceId}/meta/embedded-signup`;

export const metaEmbeddedSignupApi = {
    config: (workspaceId: number) => api.get<MetaEmbeddedSignupConfig>(`${workspaceBase(workspaceId)}/config`),
    complete: (
        workspaceId: number,
        body: {
            code: string;
            waba_id: string;
            phone_number_id: string;
            business_id?: string;
            pin: string;
        },
    ) => api.post<WhatsAppAccount>(`${workspaceBase(workspaceId)}/complete`, body),
};
