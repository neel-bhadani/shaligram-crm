import { readonly, ref } from 'vue'

/*
 | One shared theme flag for the whole app, on the same module-scope pattern as
 | useToast: the sidebar toggle writes it, nothing else needs to, and every
 | component that cares — the toggle itself, StageBadge, the chart configs —
 | reads the same ref rather than each keeping its own copy that could drift.
 |
 | The `.dark` class on <html> is what Tailwind's dark: variant is scoped to
 | (see the `@custom-variant dark` line in app.css) and is also what the inline
 | script in app.blade.php sets before Vue ever mounts, so the class and this
 | ref have to agree from the first paint onward — see init() below.
 */

const STORAGE_KEY = 'theme'

export const isDark = ref(document.documentElement.classList.contains('dark'))

function apply(dark) {
    document.documentElement.classList.toggle('dark', dark)
    isDark.value = dark
}

/**
 * Flips the theme and remembers the choice as an explicit override — from
 * here on the system preference is ignored even if it later changes, because
 * a reader who picked dark for themselves is not asking to be corrected by
 * their OS falling back to light at sunset.
 */
export function toggleTheme() {
    const next = !isDark.value

    apply(next)
    localStorage.setItem(STORAGE_KEY, next ? 'dark' : 'light')
}

/*
 | The blocking script in app.blade.php already set .dark (or not) on <html>
 | before this module ever ran — `isDark` above reads that same class as its
 | initial value, so there is nothing left to do on mount for a reader who
 | already has a stored choice or is loading the page for the first time.
 |
 | What is left is the case where they never chose and the OS preference
 | changes under them — switches to dark at sunset, say — while the tab stays
 | open. Only listened for as long as `localStorage` holds no override, so a
 | manual toggle permanently stops this from having any further say.
 */
export function watchSystemPreference() {
    const media = window.matchMedia('(prefers-color-scheme: dark)')

    media.addEventListener('change', event => {
        if (localStorage.getItem(STORAGE_KEY)) return

        apply(event.matches)
    })
}

export function useTheme() {
    return { isDark: readonly(isDark), toggleTheme }
}
