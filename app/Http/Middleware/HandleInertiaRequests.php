<?php

namespace App\Http\Middleware;

use App\Services\AlertService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->display_name,
                    'email' => $user->email,
                    'role' => $user->role,
                    /*
                     | Resolved server-side, because it is a permission and not
                     | a role: a sales manager granted see_all_leads is not an
                     | admin but does see the whole pipeline. AppLayout reads it
                     | to decide whether the Reports group lists its assigned-to
                     | links, so the sidebar offers exactly what
                     | ReportController::dimensions() offers. Presentation only —
                     | scopeVisibleTo is what actually decides which rows come
                     | back, whatever the sidebar shows.
                     */
                    'seeAllLeads' => $user->can_('see_all_leads'),
                    /*
                     | The `export_data` permission, resolved server-side so the
                     | sidebar can draw the Export Data link only for the people
                     | the route will actually let through. The route itself is
                     | the real door — this only keeps the link off a sidebar
                     | that leads to a 403.
                     */
                    'canExportData' => $user->can_('export_data'),
                    // Bulk import requires both manual-create permission and an allowed role.
                    'canImportLeads' => $user->is_active && in_array($user->role, ['admin', 'salesperson'], true) && $user->can_('add_leads'),
                ] : null,
            ],

            /*
             | The bell, on every page.
             |
             | Shared rather than fetched, because a header that has to make its
             | own request would show a stale or empty count for the first
             | second of every navigation — and the count is the entire point of
             | a bell. Two cheap indexed queries: a COUNT and ten rows.
             |
             | The list includes alerts that have already been read. A bell that
             | emptied itself the moment you looked at it gives you no way back
             | to the one you glanced at and closed; the COUNT is what tracks
             | unread, the list is recent history.
             |
             | Nothing here needs a visibility check. An alert names its
             | recipient, and whether that person should ever have been told
             | about the lead was settled before the row was written — see
             | AlertService::raise().
             */
            'alerts' => $user ? fn () => [
                'unread' => app(AlertService::class)->unreadCount($user),
                'recent' => app(AlertService::class)->recent($user)->map(fn ($alert) => [
                    'id' => $alert->id,
                    'title' => $alert->title,
                    'body' => $alert->body,
                    'severity' => $alert->severity,
                    'read' => $alert->read_at !== null,
                    'created_at' => $alert->created_at?->toIso8601String(),
                    'lead' => $alert->lead?->full_name,
                ]),
            ] : null,

            // read once in AppLayout and shown as a toast
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // things the app decided on its own — an auto-lost lead, a handover
                'warning' => fn () => $request->session()->get('warning'),
            ],
        ]);
    }
}
