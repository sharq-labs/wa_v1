import { beforeEach, describe, expect, it, vi } from 'vitest';
import { launchMetaEmbeddedSignup, type MetaEmbeddedSignupConfig } from './metaEmbeddedSignup';

const config: MetaEmbeddedSignupConfig = {
    app_id: '123456789',
    config_id: 'config-123',
    graph_api_version: 'v21.0',
    enabled: true,
    missing: [],
};

describe('Meta Embedded Signup browser flow', () => {
    let loginCallback: ((response: { authResponse?: { code?: string } | null }) => void) | null;

    beforeEach(() => {
        loginCallback = null;
        window.FB = {
            init: vi.fn(),
            login: vi.fn((callback) => {
                loginCallback = callback;
            }),
        };
    });

    it('waits for both the authorization code and WhatsApp session data', async () => {
        const promise = launchMetaEmbeddedSignup(config, 1_000);
        await Promise.resolve();

        expect(window.FB?.login).toHaveBeenCalledWith(
            expect.any(Function),
            expect.objectContaining({
                config_id: 'config-123',
                response_type: 'code',
                override_default_response_type: true,
            }),
        );

        loginCallback?.({ authResponse: { code: 'auth-code' } });

        window.dispatchEvent(
            new MessageEvent('message', {
                origin: 'https://www.facebook.com',
                data: JSON.stringify({
                    type: 'WA_EMBEDDED_SIGNUP',
                    event: 'FINISH',
                    data: {
                        waba_id: 'waba-123',
                        phone_number_id: 'phone-123',
                        business_id: 'business-123',
                    },
                }),
            }),
        );

        await expect(promise).resolves.toEqual({
            code: 'auth-code',
            wabaId: 'waba-123',
            phoneNumberId: 'phone-123',
            businessId: 'business-123',
        });
    });

    it('ignores Embedded Signup messages from untrusted origins', async () => {
        const promise = launchMetaEmbeddedSignup(config, 1_000);
        await Promise.resolve();
        loginCallback?.({ authResponse: { code: 'auth-code' } });

        window.dispatchEvent(
            new MessageEvent('message', {
                origin: 'https://evil.example',
                data: JSON.stringify({
                    type: 'WA_EMBEDDED_SIGNUP',
                    event: 'FINISH',
                    data: { waba_id: 'wrong', phone_number_id: 'wrong' },
                }),
            }),
        );

        window.dispatchEvent(
            new MessageEvent('message', {
                origin: 'https://web.facebook.com',
                data: {
                    type: 'WA_EMBEDDED_SIGNUP',
                    event: 'FINISH',
                    data: { waba_id: 'waba-123', phone_number_id: 'phone-123' },
                },
            }),
        );

        await expect(promise).resolves.toMatchObject({
            wabaId: 'waba-123',
            phoneNumberId: 'phone-123',
        });
    });

    it('rejects when the customer cancels Meta onboarding', async () => {
        const promise = launchMetaEmbeddedSignup(config, 1_000);
        await Promise.resolve();

        window.dispatchEvent(
            new MessageEvent('message', {
                origin: 'https://www.facebook.com',
                data: JSON.stringify({
                    type: 'WA_EMBEDDED_SIGNUP',
                    event: 'CANCEL',
                    data: {},
                }),
            }),
        );

        await expect(promise).rejects.toThrow('cancelled');
    });
});
