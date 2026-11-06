import { applyTheme, DARK, LIGHT, resolveTheme, setTheme } from '@waynestate/wayne-ui-vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';

export type Appearance = 'light' | 'dark' | 'system';

// WayneUI owns the theme: its `wsu-theme` key, its [data-theme] attribute and
// its .dark class. This composable is the settings page's view of that one
// switch, so the app bar's sun and moon and the Appearance page can never
// disagree. Light, dark, or "whatever my laptop says": three settings, one seam.
const STORAGE_KEY = 'wsu-theme';

// Before WayneUI, the starter kit kept its own `appearance` key.
const LEGACY_KEY = 'appearance';

function readAppearance(): Appearance {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored === DARK) return 'dark';
        if (stored === LIGHT) return 'light';
    } catch {
        // Storage is off (private browsing): the system setting applies.
    }

    return 'system';
}

export function updateTheme(value: Appearance) {
    if (value === 'system') {
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch {
            // Nothing stored, nothing to forget.
        }
        applyTheme(resolveTheme());

        return;
    }

    setTheme(value === 'dark' ? DARK : LIGHT);
}

/** Carry a choice made under the old `appearance` key over to WayneUI's, once. */
function migrateLegacyChoice() {
    try {
        const legacy = localStorage.getItem(LEGACY_KEY);
        if (legacy === null) return;

        localStorage.removeItem(LEGACY_KEY);
        if ((legacy === 'light' || legacy === 'dark') && localStorage.getItem(STORAGE_KEY) === null) {
            localStorage.setItem(STORAGE_KEY, legacy === 'dark' ? DARK : LIGHT);
        }
    } catch {
        // Storage is off; nothing to carry over.
    }
}

export function initializeTheme() {
    migrateLegacyChoice();
    // The pre-paint script in app.blade.php has usually done this already; this
    // covers the first load after a migration and any page without the script.
    applyTheme(resolveTheme());
}

export function useAppearance() {
    const appearance = ref<Appearance>('system');

    const sync = () => {
        appearance.value = readAppearance();
    };

    onMounted(() => {
        sync();
        // The app bar's toggle changes the theme too; follow it.
        document.documentElement.addEventListener('wsu:themechange', sync);
    });

    onBeforeUnmount(() => {
        document.documentElement.removeEventListener('wsu:themechange', sync);
    });

    function updateAppearance(value: Appearance) {
        updateTheme(value);
        appearance.value = value;
    }

    return {
        appearance,
        updateAppearance,
    };
}
