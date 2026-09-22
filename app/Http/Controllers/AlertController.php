<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsAlerts;
use App\Models\Alert;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The bell, and the page behind it.
 *
 * Not admin-only, and it must not become admin-only: a telecaller is told when
 * their own follow-up is three days late, and the header carries their unread
 * count on every page. An alerts page they could not open would be a count
 * pointing at a 403.
 *
 * Privacy is not enforced here. An alert names its recipient in `user_id`, so
 * this is a plain scope on that column; the question of whether somebody should
 * ever have been told about a lead was answered before the row was written, in
 * AlertService::raise(), which is the only place that can answer it — an alert
 * title carries the lead's name, so hiding the row afterwards would be too
 * late.
 *
 * READING AN ALERT CHANGES NOTHING ABOUT THE LEAD. It writes `read_at` on this
 * row. It does not complete a to-do, move a stage, or count as contact. That is
 * the entire difference between an alert and a follow-up, and it is why the two
 * live in different tables.
 */
class AlertController extends Controller
{
    use ListsAlerts;

    public function index(Request $request)
    {
        return Inertia::render('Alerts/Index', $this->alertList($request, $request->user()) + [
            'thresholds' => config('crm.alerts'),
        ]);
    }

    /**
     * Mark one read, and go where it points.
     *
     * The redirect is the reason this is a POST and not a link with a query
     * string: clicking an alert should both open the lead and stop it counting,
     * and doing that in one request means the two can never disagree.
     */
    public function read(Request $request, Alert $alert)
    {
        abort_unless($alert->user_id === $request->user()->id, 403);

        // already read is not an error and must not move the timestamp — the
        // Alerts page sorts and groups on it
        if (! $alert->read_at) {
            $alert->update(['read_at' => now()]);
        }

        return $alert->action_url
            ? redirect()->to($this->sameOriginUrl($alert->action_url))
            : back();
    }

    /**
     * The stored destination, without the host that generated it.
     *
     * `action_url` is written as an absolute `route()` url when the alert is
     * raised. Everywhere that happens the URL is pinned to whichever host the
     * process was wired up to — the local `APP_URL` is `127.0.0.1`, so the
     * alert points at `http://127.0.0.1:8000/...` even when the browser is
     * standing on `localhost:8000`, and the front end follows this redirect
     * through an XHR, where pointing at a different host is a cross-origin
     * request the browser refuses outright.
     *
     * Only the path ever matters for navigation — the alert's job is to open a
     * page, on whatever host the reader happens to be using. The query string
     * still carries the payload (the lead's mobile, a pending-users filter), so
     * it is kept; the scheme and host are not, which makes the redirect
     * relative and therefore the reader's origin.
     */
    private function sameOriginUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($path === '') {
            return $url;
        }

        $query = parse_url($url, PHP_URL_QUERY);

        return $query ? $path.'?'.$query : $path;
    }

    public function readAll(Request $request)
    {
        $marked = Alert::for($request->user())->unread()->update(['read_at' => now()]);

        return back()->with('success', $marked === 1
            ? '1 alert marked as read.'
            : "{$marked} alerts marked as read.");
    }
}
