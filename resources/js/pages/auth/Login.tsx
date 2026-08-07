import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { Link, useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { authApi } from '@/api';
import { Button, FieldError, Input, Label } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/lib/i18n';
import { useAuthStore } from '@/stores/authStore';
import AuthShell from './AuthShell';

const schema = z.object({
    email: z.string().email(),
    password: z.string().min(1),
    remember: z.boolean().optional(),
});

type FormValues = z.infer<typeof schema>;

export default function Login() {
    const { t } = useI18n();
    const navigate = useNavigate();
    const setUser = useAuthStore((s) => s.setUser);

    const {
        register,
        handleSubmit,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<FormValues>({ resolver: zodResolver(schema) });

    const login = useMutation({
        // Failures surface on the password field; a toast on top would be noise.
        meta: { silent: true },
        mutationFn: authApi.login,
        onSuccess: (response) => {
            setUser(response.data.user);
            navigate('/');
        },
        onError: (error) => {
            if (error instanceof ApiError) {
                setError('password', { message: error.message });
            }
        },
    });

    return (
        <AuthShell title={t('auth.welcome_back')} subtitle={t('auth.login')}>
            <form onSubmit={handleSubmit((values) => login.mutate(values))} className="space-y-5">
                <div>
                    <Label>{t('auth.email')}</Label>
                    <Input type="email" autoComplete="email" placeholder={t('auth.email_placeholder')} {...register('email')} />
                    <FieldError error={errors.email?.message} />
                </div>
                <div>
                    <Label>{t('auth.password')}</Label>
                    <Input type="password" autoComplete="current-password" {...register('password')} />
                    <FieldError error={errors.password?.message} />
                </div>
                <div className="flex items-center justify-between text-sm">
                    <label className="flex cursor-pointer items-center gap-2 text-slate-600">
                        <input type="checkbox" className="h-4 w-4 accent-brand-600" {...register('remember')} />
                        {t('auth.remember')}
                    </label>
                    <Link to="/forgot-password" className="font-medium text-brand-700 hover:text-brand-800 hover:underline">
                        {t('auth.forgot')}
                    </Link>
                </div>
                <Button type="submit" disabled={isSubmitting || login.isPending} className="w-full !py-3 text-[15px]">
                    {t('auth.login')}
                </Button>
                <p className="text-center text-sm text-slate-500">
                    {t('auth.no_account')}{' '}
                    <Link to="/register" className="font-semibold text-brand-700 hover:text-brand-800 hover:underline">
                        {t('auth.register')}
                    </Link>
                </p>
            </form>
        </AuthShell>
    );
}
