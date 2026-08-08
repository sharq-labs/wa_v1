export interface MetaEmbeddedSignupConfig {
    app_id: string | null;
    config_id: string | null;
    graph_api_version: string;
    enabled: boolean;
    missing?: string[];
}

export interface MetaEmbeddedSignupResult {
    code: string;
    wabaId: string;
    phoneNumberId: string;
    businessId?: string;
}

type FacebookLoginResponse = {
    authResponse?: {
        code?: string;
    } | null;
    status?: string;
};

type FacebookSdk = {
    init: (options: { appId: string; cookie: boolean; xfbml: boolean; version: string }) => void;
    login: (
        callback: (response: FacebookLoginResponse) => void,
        options: {
            config_id: string;
            response_type: 'code';
            override_default_response_type: true;
            extras: {
                setup: Record<string, never>;
                sessionInfoVersion: '3';
            };
        },
    ) => void;
};

declare global {
    interface Window {
        FB?: FacebookSdk;
    }
}

const SDK_ID = 'facebook-jssdk';
const SDK_URL = 'https://connect.facebook.net/en_US/sdk.js';
const DEFAULT_TIMEOUT_MS = 120_000;

let sdkPromise: Promise<void> | null = null;
let initializedKey: string | null = null;

function isTrustedFacebookOrigin(origin: string): boolean {
    try {
        const url = new URL(origin);

        return url.protocol === 'https:' && (url.hostname === 'facebook.com' || url.hostname.endsWith('.facebook.com'));
    } catch {
        return false;
    }
}

function parseMessageData(raw: unknown): Record<string, unknown> | null {
    if (typeof raw === 'string') {
        try {
            const parsed = JSON.parse(raw);

            return parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : null;
        } catch {
            return null;
        }
    }

    return raw && typeof raw === 'object' ? (raw as Record<string, unknown>) : null;
}

function initializeSdk(config: MetaEmbeddedSignupConfig): void {
    if (!window.FB || !config.app_id) {
        throw new Error('Facebook SDK did not initialize correctly.');
    }

    const key = `${config.app_id}:${config.graph_api_version}`;
    if (initializedKey === key) return;

    window.FB.init({
        appId: config.app_id,
        cookie: true,
        xfbml: false,
        version: config.graph_api_version,
    });
    initializedKey = key;
}

export async function loadFacebookSdk(config: MetaEmbeddedSignupConfig): Promise<void> {
    if (!config.app_id) throw new Error('Meta App ID is not configured.');

    if (window.FB) {
        initializeSdk(config);
        return;
    }

    if (!sdkPromise) {
        sdkPromise = new Promise<void>((resolve, reject) => {
            const existing = document.getElementById(SDK_ID) as HTMLScriptElement | null;
            const script = existing ?? document.createElement('script');

            const onLoad = () => {
                try {
                    initializeSdk(config);
                    resolve();
                } catch (error) {
                    sdkPromise = null;
                    reject(error);
                }
            };

            const onError = () => {
                sdkPromise = null;
                reject(new Error('Could not load the Facebook SDK. Check your connection and allowed domains.'));
            };

            script.addEventListener('load', onLoad, { once: true });
            script.addEventListener('error', onError, { once: true });

            if (!existing) {
                script.id = SDK_ID;
                script.async = true;
                script.defer = true;
                script.crossOrigin = 'anonymous';
                script.src = SDK_URL;
                document.head.appendChild(script);
            }
        });
    }

    await sdkPromise;
    initializeSdk(config);
}

export async function launchMetaEmbeddedSignup(
    config: MetaEmbeddedSignupConfig,
    timeoutMs: number = DEFAULT_TIMEOUT_MS,
): Promise<MetaEmbeddedSignupResult> {
    const configId = config.config_id;

    if (!config.enabled || !config.app_id || !configId) {
        throw new Error('Meta Embedded Signup is not fully configured.');
    }

    await loadFacebookSdk(config);

    return new Promise<MetaEmbeddedSignupResult>((resolve, reject) => {
        let code: string | null = null;
        let sessionData: { wabaId: string; phoneNumberId: string; businessId?: string } | null = null;
        let settled = false;

        const cleanup = () => {
            window.removeEventListener('message', sessionInfoListener);
            window.clearTimeout(timer);
        };

        const finishIfReady = () => {
            if (settled || !code || !sessionData) return;
            settled = true;
            cleanup();
            resolve({ code, ...sessionData });
        };

        const fail = (message: string) => {
            if (settled) return;
            settled = true;
            cleanup();
            reject(new Error(message));
        };

        const sessionInfoListener = (event: MessageEvent) => {
            if (!isTrustedFacebookOrigin(event.origin)) return;

            const payload = parseMessageData(event.data);
            if (!payload || payload.type !== 'WA_EMBEDDED_SIGNUP') return;

            if (payload.event === 'CANCEL') {
                fail('WhatsApp connection was cancelled.');
                return;
            }

            if (payload.event === 'ERROR') {
                fail('Meta could not finish WhatsApp Embedded Signup.');
                return;
            }

            if (payload.event !== 'FINISH') return;

            const data = payload.data;
            if (!data || typeof data !== 'object') {
                fail('Meta finished signup without returning WhatsApp account details.');
                return;
            }

            const result = data as Record<string, unknown>;
            const wabaId = typeof result.waba_id === 'string' ? result.waba_id : '';
            const phoneNumberId = typeof result.phone_number_id === 'string' ? result.phone_number_id : '';
            const businessId = typeof result.business_id === 'string' ? result.business_id : undefined;

            if (!wabaId || !phoneNumberId) {
                fail('Meta did not return the WABA ID and phone number ID.');
                return;
            }

            sessionData = { wabaId, phoneNumberId, businessId };
            finishIfReady();
        };

        const timer = window.setTimeout(
            () => fail('Meta Embedded Signup timed out. Close any stale Meta windows and try again.'),
            timeoutMs,
        );

        window.addEventListener('message', sessionInfoListener);

        window.FB!.login(
            (response) => {
                const authCode = response.authResponse?.code;
                if (!authCode) {
                    fail('Meta login did not return an authorization code.');
                    return;
                }

                code = authCode;
                finishIfReady();
            },
            {
                config_id: configId,
                response_type: 'code',
                override_default_response_type: true,
                extras: {
                    setup: {},
                    sessionInfoVersion: '3',
                },
            },
        );
    });
}
