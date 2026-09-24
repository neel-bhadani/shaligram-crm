import { nextTick, onMounted, onUnmounted, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { cleanUrl } from '../lib/cleanUrl.js'
import { withoutEmpty } from '../lib/withoutEmpty.js'

/**
 * One filter visit: a GET with the page's filters, and a clean address bar
 * once it lands.
 *
 * The request is unchanged in kind — same verb, same parameters, same
 * controller — so nothing on the server had to move. `cleanUrl` runs in
 * `onFinish`, and again on mount, which is what covers a filter arriving on a
 * link: the dashboard's follow-up panels link to `/todos?tab=overdue`, the
 * server honours that tab on the way in, and the parameter is wiped on the way
 * out.
 *
 * `keep` names the query keys that do NOT get wiped. It is empty for every
 * page but the dashboard, whose cross-filters are a view rather than a
 * preference: a stage or a source somebody picked is the thing they would send
 * to a colleague, so it stays in the address bar and a refresh lands on the
 * same filtered dashboard rather than on the unfiltered one.
 *
 * @param  {string}    url   the page's own route
 * @param  {string[]}  keep  query keys to leave in the address bar
 */
export function useFilterVisit(url, keep = []) {
    const base = new URL(url, window.location.origin).pathname

    const clean = () => cleanUrl(base, keep)

    onMounted(clean)

    /*
     | A full page load needs a second pass. The page mounts before Inertia has
     | written its first history entry, and that write puts the query string
     | straight back — so a link opened in a new tab kept its `?tab=`. Inertia
     | fires `navigate` once the entry is written, and cleaning there sticks.
     */
    const stopListening = router.on('navigate', clean)
    onUnmounted(stopListening)

    const visit = (params, options = {}) =>
        router.get(url, withoutEmpty(params), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            ...options,
            onFinish: (...args) => {
                clean()
                options.onFinish?.(...args)
            },
        })

    return { visit, cleanUrl: clean }
}

/*
 | Why every filter visit sends `reset=1`, given that withoutEmpty() has just
 | dropped the empty keys.
 |
 | The controller merges what arrives over what it already holds, so on its own
 | an omitted key means "leave that filter alone" — which would make emptying a
 | single box impossible once the empties are gone. `reset=1` drops the stored
 | state first, making the request the whole instruction: what is named is on,
 | what is missing is off. Each page already names every key it owns on every
 | visit, so the resolved filters are identical to what the empty values
 | produced — the same query, from a shorter request.
 */

/**
 * The 300ms debounce on the filter inputs, with a way to change the fields
 * without waking it.
 */
export function useDebouncedFilters(fields, push, delay = 300) {
    let timer
    let skip = false

    onUnmounted(() => clearTimeout(timer))

    watch(fields, () => {
        if (skip) return

        clearTimeout(timer)
        timer = setTimeout(push, delay)
    })

    return {
        /** Drop a queued push: a tab click and Clear both go out at once. */
        cancel: () => clearTimeout(timer),

        /**
         * Clear empties every field itself and then sends its own visit.
         * Letting the watcher see that would put a second, pointless visit on
         * the wire 300ms behind it.
         *
         * Pre-flush watchers run inside the flush and `nextTick` lands after
         * it, so the flag covers exactly this change — and comes back down
         * even when nothing actually changed and the watcher never ran.
         */
        silently(mutate) {
            skip = true
            mutate()
            nextTick(() => { skip = false })
        },
    }
}
