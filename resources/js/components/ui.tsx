import { clsx } from 'clsx';
import { AlertTriangle, Check, CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, Info, X } from 'lucide-react';
import {
    Children,
    forwardRef,
    isValidElement,
    useEffect,
    useId,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
    type ButtonHTMLAttributes,
    type ChangeEvent,
    type CSSProperties,
    type InputHTMLAttributes,
    type ReactElement,
    type ReactNode,
    type SelectHTMLAttributes,
    type TextareaHTMLAttributes,
} from 'react';
import { createPortal } from 'react-dom';
import { useI18n } from '@/lib/i18n';

export const Button = forwardRef<
    HTMLButtonElement,
    ButtonHTMLAttributes<HTMLButtonElement> & {
        variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
        size?: 'sm' | 'md';
        /** Shows an inline spinner and blocks input — a disabled button alone reads as broken. */
        loading?: boolean;
    }
>(function Button({ variant = 'primary', size = 'md', loading = false, className, children, disabled, ...props }, ref) {
    return (
        <button
            ref={ref}
            disabled={disabled || loading}
            aria-busy={loading || undefined}
            className={clsx(
                'inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-lg font-medium transition-all',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600',
                'disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
                size === 'sm' ? 'px-3.5 py-2 text-[15px]' : 'px-4 py-2.5 text-base',
                variant === 'primary' && 'bg-brand-600 text-white shadow-xs hover:bg-brand-700 active:bg-brand-800',
                variant === 'secondary' &&
                    'border border-slate-300 bg-white text-slate-700 shadow-xs hover:bg-slate-50 active:bg-slate-100',
                variant === 'danger' &&
                    'bg-red-600 text-white shadow-xs hover:bg-red-700 active:bg-red-800 focus-visible:outline-red-600',
                variant === 'ghost' && 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 active:bg-slate-200',
                className,
            )}
            {...props}
        >
            {loading && (
                <span
                    className="h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-current/25 border-t-current"
                    aria-hidden="true"
                />
            )}
            {children}
        </button>
    );
});

const fieldBase =
    'w-full rounded-xl border border-slate-300/70 bg-panel px-3.5 py-2.5 text-base text-slate-900 shadow-card transition-colors ' +
    'placeholder-slate-400 hover:border-slate-400 ' +
    'focus:border-brand-500 focus:outline-none focus:ring-[3px] focus:ring-brand-500/15 ' +
    'disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500';

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(function Input(
    { className, ...props },
    ref,
) {
    return <input ref={ref} className={clsx(fieldBase, className)} {...props} />;
});

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(function Textarea(
    { className, ...props },
    ref,
) {
    return <textarea ref={ref} className={clsx(fieldBase, className)} {...props} />;
});

type SelectOption = { value: string; label: string; disabled?: boolean };

function collectSelectOptions(children: ReactNode): SelectOption[] {
    const options: SelectOption[] = [];

    Children.forEach(children, (child) => {
        if (!isValidElement(child)) return;

        const el = child as ReactElement<{ value?: string | number; disabled?: boolean; children?: ReactNode; label?: string }>;
        const type = el.type;

        if (type === 'option') {
            options.push({
                value: String(el.props.value ?? ''),
                label: String(Children.toArray(el.props.children).join('') || el.props.value || ''),
                disabled: Boolean(el.props.disabled),
            });
            return;
        }

        if (type === 'optgroup') {
            options.push(...collectSelectOptions(el.props.children));
        }
    });

    return options;
}

/**
 * Custom select — keeps the familiar `<Select><option/></Select>` API while
 * rendering a styled menu (native OS option lists can't be themed).
 */
export function Select({
    className,
    children,
    value,
    defaultValue,
    onChange,
    disabled,
    id,
    name,
    required,
    size = 'md',
    'aria-label': ariaLabel,
}: Omit<SelectHTMLAttributes<HTMLSelectElement>, 'size'> & { size?: 'sm' | 'md' }) {
    const uid = useId();
    const listboxId = `${uid}-listbox`;
    const options = useMemo(() => collectSelectOptions(children), [children]);
    const isControlled = value !== undefined;
    const [internalValue, setInternalValue] = useState(String(defaultValue ?? options[0]?.value ?? ''));
    const selectedValue = String(isControlled ? value : internalValue);
    const selected = options.find((o) => o.value === selectedValue) ?? options[0];

    const [open, setOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(-1);
    const [menuStyle, setMenuStyle] = useState<CSSProperties>({});
    const rootRef = useRef<HTMLDivElement>(null);
    const buttonRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);

    const enabledIndexes = useMemo(
        () => options.map((o, i) => (!o.disabled ? i : -1)).filter((i) => i >= 0),
        [options],
    );

    const updateMenuPosition = () => {
        const trigger = buttonRef.current;
        if (!trigger) return;

        const rect = trigger.getBoundingClientRect();
        const viewportH = window.innerHeight;
        const viewportW = window.innerWidth;
        const gap = 6;
        const menuHeight = Math.min(280, options.length * 40 + 12);
        const spaceBelow = viewportH - rect.bottom - gap;
        const openUp = spaceBelow < menuHeight && rect.top > spaceBelow;
        const width = Math.max(rect.width, 160);
        const left = Math.min(Math.max(8, rect.left), viewportW - width - 8);

        setMenuStyle({
            position: 'fixed',
            top: openUp ? undefined : rect.bottom + gap,
            bottom: openUp ? viewportH - rect.top + gap : undefined,
            left,
            width,
            maxHeight: Math.min(280, openUp ? rect.top - gap - 8 : spaceBelow - 8),
            zIndex: 80,
        });
    };

    useLayoutEffect(() => {
        if (!open) return;
        updateMenuPosition();
        const onReposition = () => updateMenuPosition();
        window.addEventListener('resize', onReposition);
        window.addEventListener('scroll', onReposition, true);
        return () => {
            window.removeEventListener('resize', onReposition);
            window.removeEventListener('scroll', onReposition, true);
        };
    }, [open, options.length]);

    useEffect(() => {
        if (!open) return;

        const selectedIdx = options.findIndex((o) => o.value === selectedValue);
        setActiveIndex(selectedIdx >= 0 ? selectedIdx : (enabledIndexes[0] ?? -1));

        const onPointerDown = (event: MouseEvent) => {
            const target = event.target as Node;
            if (rootRef.current?.contains(target) || menuRef.current?.contains(target)) return;
            setOpen(false);
        };
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                setOpen(false);
                buttonRef.current?.focus();
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open, options, selectedValue, enabledIndexes]);

    useEffect(() => {
        if (!open || activeIndex < 0) return;
        const el = menuRef.current?.querySelector<HTMLElement>(`[data-option-index="${activeIndex}"]`);
        el?.scrollIntoView({ block: 'nearest' });
    }, [activeIndex, open]);

    const commit = (next: string) => {
        if (!isControlled) setInternalValue(next);
        if (onChange) {
            const event = {
                target: { value: next, name: name ?? '' },
                currentTarget: { value: next, name: name ?? '' },
                stopPropagation() {},
                preventDefault() {},
            } as ChangeEvent<HTMLSelectElement>;
            onChange(event);
        }
        setOpen(false);
        buttonRef.current?.focus();
    };

    const moveActive = (direction: 1 | -1) => {
        if (enabledIndexes.length === 0) return;
        const currentPos = enabledIndexes.indexOf(activeIndex);
        const nextPos =
            currentPos === -1
                ? direction === 1
                    ? 0
                    : enabledIndexes.length - 1
                : (currentPos + direction + enabledIndexes.length) % enabledIndexes.length;
        setActiveIndex(enabledIndexes[nextPos]);
    };

    return (
        <div ref={rootRef} className={clsx('relative', className)}>
            <button
                ref={buttonRef}
                type="button"
                id={id}
                disabled={disabled}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={open ? listboxId : undefined}
                aria-label={ariaLabel}
                aria-required={required || undefined}
                onClick={() => !disabled && setOpen((v) => !v)}
                onKeyDown={(event) => {
                    if (disabled) return;
                    if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        if (!open) setOpen(true);
                        else if (event.key === 'ArrowDown') moveActive(1);
                        else if (event.key === 'Enter' || event.key === ' ') {
                            const opt = options[activeIndex];
                            if (opt && !opt.disabled) commit(opt.value);
                        }
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        if (!open) setOpen(true);
                        else moveActive(-1);
                    }
                }}
                className={clsx(
                    'flex w-full cursor-pointer items-center justify-between gap-2 border border-slate-300/70 bg-panel text-start text-slate-900 shadow-card transition-colors',
                    'hover:border-slate-400',
                    'focus:border-brand-500 focus:outline-none focus:ring-[3px] focus:ring-brand-500/15',
                    'disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500',
                    open && 'border-brand-500 ring-[3px] ring-brand-500/15',
                    size === 'sm'
                        ? 'rounded-lg px-2.5 py-1.5 text-[13px] leading-snug'
                        : 'rounded-xl px-3.5 py-2.5 text-base',
                )}
            >
                <span className={clsx('min-w-0 flex-1 truncate', !selected?.label && 'text-slate-400')}>
                    {selected?.label || '—'}
                </span>
                <ChevronDown
                    size={size === 'sm' ? 15 : 17}
                    className={clsx('shrink-0 text-slate-400 transition-transform duration-200', open && 'rotate-180 text-brand-600')}
                />
            </button>

            <select
                tabIndex={-1}
                aria-hidden="true"
                name={name}
                value={selectedValue}
                required={required}
                disabled={disabled}
                onChange={() => {}}
                className="pointer-events-none absolute h-0 w-0 opacity-0"
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value} disabled={option.disabled}>
                        {option.label}
                    </option>
                ))}
            </select>

            {open &&
                createPortal(
                    <div
                        ref={menuRef}
                        id={listboxId}
                        role="listbox"
                        style={menuStyle}
                        className="dropdown-menu overflow-y-auto rounded-xl border border-slate-200/90 bg-panel p-1 shadow-pop"
                    >
                        {options.length === 0 ? (
                            <p className="px-3 py-2.5 text-sm text-slate-400">—</p>
                        ) : (
                            options.map((option, index) => {
                                const isSelected = option.value === selectedValue;
                                const isActive = index === activeIndex;
                                return (
                                    <button
                                        key={`${option.value}-${index}`}
                                        type="button"
                                        role="option"
                                        data-option-index={index}
                                        aria-selected={isSelected}
                                        disabled={option.disabled}
                                        onMouseEnter={() => setActiveIndex(index)}
                                        onClick={() => {
                                            if (!option.disabled) commit(option.value);
                                        }}
                                        className={clsx(
                                            'flex w-full cursor-pointer items-center gap-2 rounded-lg px-3 py-2.5 text-start text-[15px] transition-colors',
                                            option.disabled && 'cursor-not-allowed opacity-40',
                                            isSelected
                                                ? 'bg-brand-100 font-semibold text-brand-900'
                                                : isActive
                                                  ? 'bg-slate-200/60 text-slate-900'
                                                  : 'text-slate-700 hover:bg-slate-200/50',
                                        )}
                                    >
                                        <span className="min-w-0 flex-1 truncate">{option.label}</span>
                                        {isSelected && <Check size={15} className="shrink-0 text-brand-700" strokeWidth={2.5} />}
                                    </button>
                                );
                            })
                        )}
                    </div>,
                    document.body,
                )}
        </div>
    );
}

export function Label({ children, className }: { children: ReactNode; className?: string }) {
    return <label className={clsx('mb-1.5 block text-[15px] font-medium text-slate-700', className)}>{children}</label>;
}

export function FieldError({ error }: { error?: string | string[] }) {
    if (!error) return null;
    const message = Array.isArray(error) ? error[0] : error;
    return <p className="mt-1 text-xs text-red-600">{message}</p>;
}

const badgeColors: Record<string, string> = {
    green: 'bg-green-50 text-green-700 ring-green-600/20',
    red: 'bg-red-50 text-red-700 ring-red-600/20',
    yellow: 'bg-amber-50 text-amber-700 ring-amber-600/25',
    blue: 'bg-blue-50 text-blue-700 ring-blue-600/20',
    slate: 'bg-slate-100 text-slate-600 ring-slate-500/20',
    purple: 'bg-purple-50 text-purple-700 ring-purple-600/20',
};

export function Badge({ color = 'slate', children }: { color?: string; children: ReactNode }) {
    return (
        <span
            className={clsx(
                'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[13px] font-medium ring-1 ring-inset',
                badgeColors[color] ?? badgeColors.slate,
            )}
        >
            {children}
        </span>
    );
}

export function QueryError({
    message,
    onRetry,
}: {
    message?: string;
    onRetry?: () => void;
}) {
    const { t } = useI18n();
    return (
        <div className="flex flex-col items-center justify-center gap-3 p-10 text-center">
            <p className="text-sm text-slate-600">{message ?? t('common.error')}</p>
            {onRetry && (
                <Button variant="secondary" onClick={onRetry}>
                    {t('common.retry')}
                </Button>
            )}
        </div>
    );
}

export function statusColor(status: string): string {
    return (
        {
            open: 'green', active: 'green', approved: 'green', connected: 'green', completed: 'green',
            online: 'green', published: 'green', sent: 'blue', delivered: 'blue', processing: 'blue',
            running: 'blue', scheduled: 'blue', read: 'purple', pending: 'yellow', waiting: 'yellow',
            paused: 'yellow', away: 'yellow', draft: 'slate', closed: 'slate', offline: 'slate',
            archived: 'slate', cancelled: 'slate', skipped: 'slate', failed: 'red', rejected: 'red',
            busy: 'red', disconnected: 'red', blocked: 'red',
        } as Record<string, string>
    )[status] ?? 'slate';
}

export function Skeleton({ className }: { className?: string }) {
    return <div className={clsx('animate-skeleton rounded-md bg-slate-200/70', className)} aria-hidden="true" />;
}

export function TableSkeleton({ rows = 5, columns = 4 }: { rows?: number; columns?: number }) {
    return (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-card">
            <div className="border-b border-slate-100 bg-slate-50 px-4 py-3">
                <Skeleton className="h-3 w-24" />
            </div>
            {Array.from({ length: rows }).map((_, row) => (
                <div key={row} className="flex items-center gap-4 border-b border-slate-100 px-4 py-3.5 last:border-0">
                    {Array.from({ length: columns }).map((_, column) => (
                        <Skeleton
                            key={column}
                            className={clsx('h-3.5', column === 0 ? 'w-40 flex-none' : 'flex-1 max-w-28')}
                        />
                    ))}
                </div>
            ))}
        </div>
    );
}

export function Alert({
    tone = 'info',
    title,
    children,
    action,
}: {
    tone?: 'info' | 'warning' | 'danger' | 'success';
    title?: string;
    children?: ReactNode;
    action?: ReactNode;
}) {
    const tones = {
        info: { wrap: 'border-sky-200 bg-sky-50', icon: 'text-sky-600', text: 'text-sky-900', Icon: Info },
        warning: { wrap: 'border-amber-200 bg-amber-50', icon: 'text-amber-600', text: 'text-amber-900', Icon: AlertTriangle },
        danger: { wrap: 'border-red-200 bg-red-50', icon: 'text-red-600', text: 'text-red-900', Icon: AlertTriangle },
        success: { wrap: 'border-emerald-200 bg-emerald-50', icon: 'text-emerald-600', text: 'text-emerald-900', Icon: CheckCircle2 },
    }[tone];
    const Icon = tones.Icon;

    return (
        <div className={clsx('flex items-start gap-3 rounded-xl border px-3.5 py-3', tones.wrap)}>
            <Icon size={16} strokeWidth={2.3} className={clsx('mt-0.5 shrink-0', tones.icon)} />
            <div className="min-w-0 flex-1">
                {title && <p className={clsx('text-sm font-semibold', tones.text)}>{title}</p>}
                {children && (
                    <div className={clsx('text-xs leading-relaxed', title ? 'mt-1 opacity-90' : '', tones.text)}>
                        {children}
                    </div>
                )}
            </div>
            {action && <div className="shrink-0">{action}</div>}
        </div>
    );
}

export function Pagination({
    page,
    lastPage,
    onChange,
    total,
}: {
    page: number;
    lastPage: number;
    onChange: (page: number) => void;
    total?: number;
}) {
    const { t } = useI18n();

    if (lastPage <= 1) return null;

    return (
        <div className="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-2.5">
            <p className="text-xs text-slate-500">
                {t('common.page_of', { current: page, last: lastPage })}
                {total !== undefined && <span className="ms-1.5 text-slate-400">· {total}</span>}
            </p>
            <div className="flex gap-1.5">
                <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => onChange(page - 1)}>
                    <ChevronLeft size={15} className="rtl:-scale-x-100" />
                    {t('common.prev')}
                </Button>
                <Button variant="secondary" size="sm" disabled={page >= lastPage} onClick={() => onChange(page + 1)}>
                    {t('common.next')}
                    <ChevronRight size={15} className="rtl:-scale-x-100" />
                </Button>
            </div>
        </div>
    );
}

export function Spinner({ className }: { className?: string }) {
    return (
        <div className={clsx('flex items-center justify-center p-8', className)}>
            <div className="h-6 w-6 animate-spin rounded-full border-2 border-slate-200 border-t-brand-600" />
        </div>
    );
}

export function Card({ className, children }: { className?: string; children: ReactNode }) {
    return <div className={clsx('rounded-xl border border-slate-200 bg-white shadow-card', className)}>{children}</div>;
}

export function EmptyState({
    title,
    description,
    icon,
    action,
}: {
    title: string;
    description?: string;
    icon?: ReactNode;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-slate-300 bg-slate-50/50 p-10 text-center">
            {icon && (
                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-white text-slate-400 shadow-xs ring-1 ring-slate-200">
                    {icon}
                </div>
            )}
            <div>
                <p className="text-sm font-medium text-slate-700">{title}</p>
                {description && <p className="mt-1 text-xs text-slate-500">{description}</p>}
            </div>
            {action}
        </div>
    );
}

export function Modal({
    open,
    onClose,
    title,
    children,
    wide,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    children: ReactNode;
    wide?: boolean;
}) {
    const { t } = useI18n();

    useEffect(() => {
        if (!open) return;
        const onKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open, onClose]);

    if (!open) return null;

    return (
        <div
            className="animate-fade-in fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-[2px]"
            onClick={onClose}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label={title}
                className={clsx(
                    'animate-modal-in max-h-[90vh] w-full overflow-y-auto rounded-2xl bg-white p-6 shadow-pop',
                    wide ? 'max-w-3xl' : 'max-w-lg',
                )}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="mb-5 flex items-center justify-between gap-3">
                    <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
                    <button
                        onClick={onClose}
                        aria-label={t('common.close')}
                        className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 focus-visible:outline-2 focus-visible:outline-brand-600"
                    >
                        <X size={16} />
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

const avatarTones = [
    'bg-brand-100 text-brand-700',
    'bg-blue-100 text-blue-700',
    'bg-purple-100 text-purple-700',
    'bg-amber-100 text-amber-700',
    'bg-rose-100 text-rose-700',
    'bg-teal-100 text-teal-700',
    'bg-indigo-100 text-indigo-700',
];

export function Avatar({ name, size = 8 }: { name?: string | null; size?: number }) {
    const label = name ?? '?';
    const initials = label
        .split(' ')
        .map((p) => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

    let hash = 0;
    for (let i = 0; i < label.length; i++) hash = (hash * 31 + label.charCodeAt(i)) | 0;
    const tone = avatarTones[Math.abs(hash) % avatarTones.length];

    return (
        <div
            className={clsx('flex shrink-0 items-center justify-center rounded-full font-semibold ring-1 ring-black/5', tone)}
            style={{ width: size * 4, height: size * 4, fontSize: size * 1.4 }}
        >
            {initials}
        </div>
    );
}

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: string; actions?: ReactNode }) {
    return (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div className="min-w-0">
                <h1 className="text-[1.75rem] font-bold tracking-tight text-slate-900 md:text-[1.9rem]">{title}</h1>
                {subtitle && <p className="mt-1.5 text-[15px] leading-relaxed text-slate-500">{subtitle}</p>}
            </div>
            <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>
        </div>
    );
}
