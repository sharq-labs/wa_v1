/**
 * Central API client. All requests flow through here — no scattered fetch
 * calls. Uses Sanctum SPA cookie auth (same-origin) with XSRF protection.
 */

export interface ApiEnvelope<T = any> {
    success: boolean;
    message: string;
    data: T;
    errors?: Record<string, string[]>;
}

export class ApiError extends Error {
    status: number;
    errors: Record<string, string[]>;

    constructor(message: string, status: number, errors: Record<string, string[]> = {}) {
        super(message);
        this.status = status;
        this.errors = errors;
    }
}

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
}

let csrfReady = false;

async function ensureCsrf(): Promise<void> {
    if (csrfReady && readCookie('XSRF-TOKEN')) return;
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' });
    csrfReady = true;
}

export async function request<T = any>(
    method: string,
    url: string,
    body?: unknown,
    options: { formData?: FormData } = {},
): Promise<ApiEnvelope<T>> {
    if (method !== 'GET') {
        await ensureCsrf();
    }

    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;

    let payload: BodyInit | undefined;
    if (options.formData) {
        payload = options.formData;
    } else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    const response = await fetch(url, {
        method,
        headers,
        body: payload,
        credentials: 'include',
    });

    if (response.status === 204) {
        return { success: true, message: '', data: undefined as T };
    }

    let json: ApiEnvelope<T>;
    try {
        json = await response.json();
    } catch {
        throw new ApiError('Unexpected server response.', response.status);
    }

    if (!response.ok || json.success === false) {
        throw new ApiError(json.message || 'Request failed.', response.status, json.errors ?? {});
    }

    return json;
}

export const api = {
    get: <T = any>(url: string) => request<T>('GET', url),
    post: <T = any>(url: string, body?: unknown) => request<T>('POST', url, body),
    put: <T = any>(url: string, body?: unknown) => request<T>('PUT', url, body),
    delete: <T = any>(url: string) => request<T>('DELETE', url),
    upload: <T = any>(url: string, formData: FormData) => request<T>('POST', url, undefined, { formData }),
};
