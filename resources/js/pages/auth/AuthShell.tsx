import { Languages } from 'lucide-react';
import type { ReactNode } from 'react';
import { useI18n } from '@/lib/i18n';

export default function AuthShell({
    title,
    subtitle,
    children,
}: {
    title: string;
    subtitle?: string;
    children: ReactNode;
}) {
    const { locale, setLocale, t } = useI18n();

    return (
        <div className="auth-shell relative flex min-h-screen bg-[#eef5f1]">
            {/* Brand plane — full-bleed visual half */}
            <aside className="relative hidden min-h-screen w-[54%] overflow-hidden lg:flex">
                <div
                    aria-hidden="true"
                    className="absolute inset-0 bg-[linear-gradient(160deg,#043f31_0%,#0b6b5c_42%,#0f3d2e_100%)]"
                />
                <div
                    aria-hidden="true"
                    className="auth-shell-glow pointer-events-none absolute -start-20 top-10 h-[22rem] w-[22rem] rounded-full bg-emerald-300/20 blur-3xl"
                />
                <div
                    aria-hidden="true"
                    className="auth-shell-glow-delay pointer-events-none absolute end-[-4rem] bottom-10 h-[24rem] w-[24rem] rounded-full bg-teal-300/15 blur-3xl"
                />
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 opacity-[0.12]"
                    style={{
                        backgroundImage:
                            'radial-gradient(rgba(255,255,255,0.7) 0.9px, transparent 0.9px)',
                        backgroundSize: '20px 20px',
                    }}
                />

                {/* Edge-to-edge chat atmosphere sits behind copy */}
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-x-0 bottom-0 h-[55%] bg-[linear-gradient(180deg,transparent_0%,rgba(0,0,0,0.18)_100%)]"
                />

                <div className="relative z-10 flex w-full flex-col px-10 py-10 xl:px-14 xl:py-12">
                    <div className="auth-shell-fade flex items-center gap-3">
                        <div className="flex h-12 w-12 items-center justify-center rounded-[15px] bg-white/12 text-[1.15rem] font-bold text-white ring-1 ring-white/30 backdrop-blur-sm">
                            W
                        </div>
                        <span className="auth-display text-[1.85rem] font-semibold tracking-tight text-white">
                            WhatsFlow
                        </span>
                    </div>

                    <div className="auth-shell-fade-delay mt-16 max-w-xl xl:mt-20">
                        <h1 className="auth-display text-[2.5rem] leading-[1.12] font-semibold tracking-tight text-white xl:text-[2.9rem]">
                            {t('auth.brand_headline')}
                        </h1>
                        <p className="mt-4 max-w-md text-[15.5px] leading-relaxed text-emerald-50/80">
                            {t('auth.brand_subline')}
                        </p>
                    </div>

                    <div className="auth-shell-fade-late mt-auto max-w-md pt-14">
                        <div className="relative">
                            <div
                                aria-hidden="true"
                                className="absolute -inset-4 rounded-[32px] bg-emerald-400/10 blur-xl"
                            />
                            <div className="relative space-y-3 rounded-[26px] border border-white/15 bg-white/[0.09] p-5 shadow-[0_30px_60px_-28px_rgba(0,0,0,0.55)] backdrop-blur-md">
                                <div className="auth-bubble-in flex justify-start">
                                    <div className="max-w-[88%] rounded-2xl rounded-ss-md bg-white px-4 py-3 text-[13.5px] leading-snug text-slate-800 shadow-sm">
                                        {t('auth.preview_customer')}
                                    </div>
                                </div>
                                <div className="auth-bubble-in-delay flex justify-end">
                                    <div className="max-w-[88%] rounded-2xl rounded-se-md bg-[#34d399] px-4 py-3 text-[13.5px] leading-snug text-emerald-950 shadow-sm">
                                        {t('auth.preview_bot')}
                                    </div>
                                </div>
                                <div className="auth-bubble-in-late flex justify-start">
                                    <div className="rounded-xl bg-black/20 px-3.5 py-2 text-[11.5px] font-medium text-emerald-50 ring-1 ring-white/10">
                                        {t('auth.preview_agent')}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>

            {/* Form plane */}
            <main className="relative flex min-h-screen w-full flex-1 flex-col lg:w-[46%]">
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 lg:hidden"
                    style={{
                        background:
                            'linear-gradient(180deg, rgba(4,63,49,0.14) 0%, transparent 38%), #eef5f1',
                    }}
                />

                <div className="relative z-10 flex items-center justify-between px-5 pt-5 sm:px-8 lg:px-10">
                    <div className="flex items-center gap-2.5 lg:opacity-0">
                        <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-linear-to-br from-brand-500 to-brand-700 text-sm font-bold text-white shadow-sm">
                            W
                        </div>
                        <span className="auth-display text-lg font-semibold tracking-tight text-slate-900">
                            WhatsFlow
                        </span>
                    </div>
                    <button
                        type="button"
                        onClick={() => setLocale(locale === 'en' ? 'ar' : 'en')}
                        className="flex items-center gap-1.5 rounded-xl border border-slate-200/90 bg-white/95 px-3 py-2 text-sm font-medium text-slate-600 shadow-xs transition hover:border-slate-300 hover:text-slate-900"
                    >
                        <Languages size={15} />
                        {locale === 'en' ? 'العربية' : 'English'}
                    </button>
                </div>

                <div className="relative z-10 flex flex-1 items-center justify-center px-5 py-10 sm:px-8 lg:px-10">
                    <div className="auth-shell-fade w-full max-w-[420px]">
                        <div className="mb-8">
                            <h2 className="auth-display text-[1.85rem] font-semibold tracking-tight text-slate-900">
                                {title}
                            </h2>
                            {subtitle && (
                                <p className="mt-1.5 text-[14px] leading-relaxed text-slate-500">{subtitle}</p>
                            )}
                        </div>
                        {children}
                    </div>
                </div>
            </main>
        </div>
    );
}
