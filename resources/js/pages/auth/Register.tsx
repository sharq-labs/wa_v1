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

const schema = z
    .object({
        name: z.string().min(2),
        workspace_name: z.string().min(2),
        email: z.string().email(),
        password: z.string().min(8),
        password_confirmation: z.string(),
    })
    .refine((data) => data.password === data.password_confirmation, {
        message: 'Passwords do not match',
        path: ['password_confirmation'],
    });

type FormValues = z.infer<typeof schema>;

export default function Register() {
    const { t } = useI18n();
    const navigate = useNavigate();
    const setUser = useAuthStore((s) => s.setUser);

    const {
        register,
        handleSubmit,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<FormValues>({ resolver: zodResolver(schema) });

    const registerMutation = useMutation({
        // Validation errors are mapped onto the individual fields below.
        meta: { silent: true },
        mutationFn: authApi.register,
        onSuccess: (response) => {
            setUser(response.data.user);
            navigate('/onboarding');
        },
        onError: (error) => {
            if (error instanceof ApiError) {
                for (const [field, messages] of Object.entries(error.errors)) {
                    setError(field as keyof FormValues, { message: messages[0] });
                }
            }
        },
    });

    return (
        <AuthShell title={t('auth.create_workspace')} subtitle={t('auth.register')}>
            <form onSubmit={handleSubmit((values) => registerMutation.mutate(values))} className="space-y-4">
                <div>
                    <Label>{t('auth.name')}</Label>
                    <Input {...register('name')} />
                    <FieldError error={errors.name?.message} />
                </div>
                <div>
                    <Label>{t('auth.workspace_name')}</Label>
                    <Input {...register('workspace_name')} />
                    <FieldError error={errors.workspace_name?.message} />
                </div>
                <div>
                    <Label>{t('auth.email')}</Label>
                    <Input type="email" {...register('email')} />
                    <FieldError error={errors.email?.message} />
                </div>
                <div>
                    <Label>{t('auth.password')}</Label>
                    <Input type="password" {...register('password')} />
                    <FieldError error={errors.password?.message} />
                </div>
                <div>
                    <Label>{t('auth.password_confirm')}</Label>
                    <Input type="password" {...register('password_confirmation')} />
                    <FieldError error={errors.password_confirmation?.message} />
                </div>
                <Button type="submit" disabled={isSubmitting || registerMutation.isPending} className="w-full">
                    {t('auth.register')}
                </Button>
                <p className="text-center text-xs text-slate-500">
                    {t('auth.have_account')}{' '}
                    <Link to="/login" className="font-medium text-brand-600 hover:underline">
                        {t('auth.login')}
                    </Link>
                </p>
            </form>
        </AuthShell>
    );
}
