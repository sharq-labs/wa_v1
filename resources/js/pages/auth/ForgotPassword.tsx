import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { authApi } from '@/api';
import { Button, Input, Label } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import AuthShell from './AuthShell';

export default function ForgotPassword() {
    const { t } = useI18n();
    const [email, setEmail] = useState('');
    const [sent, setSent] = useState(false);

    const send = useMutation({
        mutationFn: () => authApi.forgotPassword(email),
        onSuccess: () => setSent(true),
    });

    return (
        <AuthShell title={t('auth.reset')}>
            {sent ? (
                <p className="text-center text-sm text-slate-600">
                    If that email exists, a reset link has been sent.
                </p>
            ) : (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        send.mutate();
                    }}
                    className="space-y-4"
                >
                    <div>
                        <Label>{t('auth.email')}</Label>
                        <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
                    </div>
                    <Button type="submit" disabled={send.isPending} className="w-full">
                        {t('auth.send_link')}
                    </Button>
                </form>
            )}
            <p className="mt-4 text-center text-xs">
                <Link to="/login" className="font-medium text-brand-600 hover:underline">
                    {t('auth.login')}
                </Link>
            </p>
        </AuthShell>
    );
}
