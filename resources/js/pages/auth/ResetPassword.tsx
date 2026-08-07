import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { authApi } from '@/api';
import { Button, FieldError, Input, Label } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/lib/i18n';
import AuthShell from './AuthShell';

export default function ResetPassword() {
    const { t } = useI18n();
    const [params] = useSearchParams();
    const navigate = useNavigate();
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [error, setError] = useState<string | null>(null);

    const reset = useMutation({
        // Rendered inline above the form.
        meta: { silent: true },
        mutationFn: () =>
            authApi.resetPassword({
                token: params.get('token') ?? '',
                email: params.get('email') ?? '',
                password,
                password_confirmation: confirmation,
            }),
        onSuccess: () => navigate('/login'),
        onError: (e) => setError(e instanceof ApiError ? e.message : 'Failed.'),
    });

    return (
        <AuthShell title={t('auth.reset')}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    reset.mutate();
                }}
                className="space-y-4"
            >
                <div>
                    <Label>{t('auth.password')}</Label>
                    <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
                </div>
                <div>
                    <Label>{t('auth.password_confirm')}</Label>
                    <Input type="password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} required />
                </div>
                <FieldError error={error ?? undefined} />
                <Button type="submit" disabled={reset.isPending} className="w-full">
                    {t('auth.reset')}
                </Button>
            </form>
        </AuthShell>
    );
}
