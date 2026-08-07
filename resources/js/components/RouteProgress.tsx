import { useIsFetching } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useI18n } from '@/lib/i18n';

/** How long a navigation alone keeps the bar up, when its data was cached. */
const NAV_GRACE_MS = 350;
/** Tail after the work ends, so a 30ms response still leaves a visible trace. */
const HIDE_DELAY_MS = 200;

/**
 * Every page is eagerly imported, so a route change swaps the component
 * instantly and then leaves the user on a bare screen until the new page's
 * query resolves — nothing tells them the click registered. This bar covers
 * that gap: it rides both the navigation itself and any first-time fetch.
 */
export default function RouteProgress() {
    const { t } = useI18n();
    const { pathname } = useLocation();

    /**
     * Background refetches are deliberately excluded. A query that already has
     * data keeps rendering it, so counting it as "loading" would make the bar
     * pulse every 15s on the admin health tab for no visible reason.
     */
    const loading = useIsFetching({ predicate: (query) => query.state.data === undefined });

    const [navPath, setNavPath] = useState(pathname);
    const [navigating, setNavigating] = useState(true);
    const [visible, setVisible] = useState(true);

    // Re-armed during render rather than in an effect, so the bar is on screen
    // in the first paint after a click instead of one frame later.
    if (navPath !== pathname) {
        setNavPath(pathname);
        setNavigating(true);
    }

    const active = navigating || loading > 0;

    if (active && !visible) setVisible(true);

    useEffect(() => {
        if (!navigating) return;
        const id = setTimeout(() => setNavigating(false), NAV_GRACE_MS);
        return () => clearTimeout(id);
    }, [navigating, pathname]);

    useEffect(() => {
        if (active || !visible) return;
        const id = setTimeout(() => setVisible(false), HIDE_DELAY_MS);
        return () => clearTimeout(id);
    }, [active, visible]);

    if (!visible) return null;

    return (
        <div className="route-progress animate-fade-in" role="status" aria-label={t('common.loading')}>
            <div className="route-progress-bar" />
        </div>
    );
}
