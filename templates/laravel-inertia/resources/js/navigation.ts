import type { BreadcrumbItem, NavItem } from '@/types';

// One list of links for every piece of chrome: the app bar and the sidebar
// (a drawer on phones) both read from here, so a link added once shows up
// everywhere it should.

export const mainNavItems: NavItem[] = [{ title: 'Dashboard', href: '/dashboard', icon: 'home' }];

export const resourceNavItems: NavItem[] = [
    { title: 'WayneUI components', href: 'https://wayneui.apps.wayne.edu/components/', icon: 'component' },
    { title: 'Sourdough docs', href: 'https://sourdough.apps.wayne.edu/docs/', icon: 'book-open' },
];

/** The shape WayneUI's AppBar, SidebarNav and Breadcrumbs take. */
export interface WayneUiLink {
    label: string;
    href: string;
    icon?: string;
    current?: boolean;
}

/** Inertia's page.url carries the query string; the nav compares paths only. */
export function pathOf(url: string): string {
    return url.split(/[?#]/)[0];
}

export function toWayneUi(items: NavItem[], currentUrl: string): WayneUiLink[] {
    const current = pathOf(currentUrl);

    return items.map((item) => ({
        label: item.title,
        href: item.href,
        icon: item.icon,
        current: item.href === current,
    }));
}

export function toCrumbs(items: BreadcrumbItem[]): WayneUiLink[] {
    return items.map((item) => ({ label: item.title, href: item.href }));
}
