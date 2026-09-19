import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import Toaster from '@/Components/Toaster.vue';
import { toast, flashToasts } from '@/composables/useToast';
import { watchSystemPreference } from '@/composables/useTheme';

watchSystemPreference();

const appName = import.meta.env.VITE_APP_NAME || 'Shaligram CRM';

/*
 | Failures that produce no flash message of their own are announced here, once
 | per request, rather than in every form. Doing it centrally is also what keeps
 | a rejected form to a single toast instead of one per invalid field.
 */

// validation — the inline field errors still do the explaining
router.on('error', () => {
    toast.error('Please correct the highlighted fields');
});

// a response Inertia cannot use: an HTML error page. Calling preventDefault
// suppresses Inertia's full-screen error modal in favour of the toast.
router.on('invalid', event => {
    const status = event.detail.response?.status;

    const message = {
        403: 'You do not have permission for this action.',
        419: 'Your session expired. Please sign in again.',
    }[status] ?? (status >= 500 ? 'Something went wrong. Please try again.' : null);

    if (!message) return;

    event.preventDefault();
    toast.error(message);
});

// no response at all — offline, DNS, a dropped connection
router.on('exception', event => {
    event.preventDefault();
    toast.error('Something went wrong. Please try again.');
});

/*
 | Server-flashed messages become toasts here rather than in a layout, so the
 | guest pages get them too — signing out lands on the login page, which has no
 | AppLayout to watch the prop.
 */
router.on('success', event => flashToasts(event.detail.page.props.flash));

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        // the first page is not an Inertia visit, so it fires no success event
        flashToasts(props.initialPage.props.flash);

        // Toaster sits beside the page, not inside it, so a single stack
        // survives navigation between layouts
        return createApp({ render: () => [h(App, props), h(Toaster)] })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
