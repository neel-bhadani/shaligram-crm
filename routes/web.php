<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\AutomationRuleController;
use App\Http\Controllers\ChannelPartnerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MessageQueueController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth'])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /* ---------------- leads ---------------- */

    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
    Route::post('/leads/check-duplicate', [LeadController::class, 'checkDuplicate'])
        ->name('leads.check-duplicate');

    /*
     | No role middleware on these three any more — LeadPolicy decides, through
     | LeadRequest::authorize() on the first two and $this->authorize() on the
     | third. `role:admin,salesperson` here would have quietly overruled the
     | per-user toggles: a telecaller granted `add_leads` would have been turned
     | away by the router before the permission was ever consulted.
     |
     | The defaults are unchanged, so this is not a widening — a telecaller with
     | untouched toggles still gets the same 403, it now comes from the policy.
     */
    Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
    Route::put('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');

    Route::middleware('role:admin')->group(function () {
        Route::delete('/todos/{todo}', [TodoController::class, 'destroy'])->name('todos.destroy');
    });

    /* ---------------- to-do ---------------- */

    Route::get('/todos', [TodoController::class, 'index'])->name('todos.index');
    Route::post('/todos', [TodoController::class, 'store'])->name('todos.store');
    Route::put('/todos/{todo}', [TodoController::class, 'update'])->name('todos.update');
    Route::post('/todos/{todo}/complete', [TodoController::class, 'complete'])
        ->name('todos.complete');

    /* ---------------- calendar ---------------- */

    /*
     | The follow-up calendar, one read-only month view.
     |
     | Auth-only on the outer group, with no further role middleware — the same
     | door every other follow-up list sits behind. Visibility is decided inside
     | the controller, by Todo::forUser() + hasLead(), so a telecaller gets
     | their own follow-ups and an admin gets every visible one; the route
     | refusing nobody is what lets one page serve both without leaking.
     */
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');

    /*
     | The clash warning's server half, and nothing more than a warning.
     |
     | Read-only, called from the four forms that book a follow-up while the
     | user is still choosing a time. It refuses nothing: there is no matching
     | validation rule on any of the save routes above, and a save that goes
     | ahead with a known clash succeeds. Every one of those forms is already
     | reachable by the user who is asking, and the one thing worth protecting
     | — the clashing lead's name — is masked by the controller when they could
     | not have opened that lead anyway.
     */
    Route::post('/follow-ups/check-conflict', [TodoController::class, 'checkConflict'])
        ->name('follow-ups.check-conflict');

    /* ---------------- reports ---------------- */

    /*
     | Two routes, ten sidebar links. Every link under Reports is one of these
     | with a different query string — ?group=source, ?status=completed — which
     | the controller resolves and stores the same way every other filter on
     | every other page is resolved and stored.
     |
     | No role middleware: both actions are scoped by scopeVisibleTo and
     | scopeForUser, so a telecaller opening either one gets a report of their
     | own work rather than a 403. The assigned-to grouping, which is the only
     | part that would be meaningless to them, is dropped by the controller.
     */
    Route::get('/reports/leads', [ReportController::class, 'leads'])->name('reports.leads');
    Route::get('/reports/followups', [ReportController::class, 'followUps'])->name('reports.followups');

    /* ---------------- users (admin only) ---------------- */

    /*
     | Every one of these is behind role:admin, on the group rather than on each
     | route, so a route added here later cannot be forgotten. The middleware
     | also re-checks is_active, which is what stops a deactivated admin's live
     | session from carrying on managing staff.
     |
     | Deliberately not permission-gated: managing staff is not one of the five
     | lead toggles and must not become grantable from inside the app.
     */
    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        /*
         | Answering a sign-up. Two POSTs of their own rather than a status
         | field on the update, because approving is not an edit: it switches
         | the account on, resets it to its role's defaults and clears the
         | alert from every admin's bell, and none of that should happen
         | because somebody ticked a box in the Edit modal.
         */
        Route::post('/users/{user}/approve', [UserController::class, 'approve'])->name('users.approve');
        Route::post('/users/{user}/reject', [UserController::class, 'reject'])->name('users.reject');
    });

    /* ---------------- projects (admin only) ---------------- */

    /*
     | Admin only, on the group so a route added here later cannot be forgotten.
     |
     | This is a real boundary rather than a tidy sidebar. Projects are the one
     | piece of reference data every lead in the database points at, the detail
     | page reports the whole pipeline for a development, and the delete route
     | touches a foreign key that cascades onto leads. A telecaller who types
     | /projects gets a 403 from the middleware; ProjectRequest::authorize()
     | says the same thing again, which is the lock that survives somebody
     | reorganising this file.
     |
     | Unlike channel partners there is no "quick add" outside the group. A
     | project is set up deliberately, before anybody files a lead against it —
     | never in the middle of the Add lead form.
     */
    Route::middleware('role:admin')->group(function () {
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        // who the project's salesperson round robin takes turns among — the
        // checkboxes on its detail page, not a page of their own
        Route::put('/projects/{project}/salespeople', [ProjectController::class, 'updateSalespeople'])
            ->name('projects.salespeople.update');
        /*
         | Soft delete, and refused outright while the project has any leads —
         | see ProjectController::destroy(). `leads.project_id` cascades on
         | delete, so this route is the one place in the application that could
         | destroy thousands of rows by accident, and it is the one place that
         | will not.
         */
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])
            ->name('projects.destroy');
    });

    /* ---------------- channel partners ---------------- */

    /*
     | CREATING a partner is part of creating a lead, so it is gated like one.
     |
     | This is the only route in the application that writes a channel partner
     | into existence — there is no POST in the admin group below, and no Add
     | button on the Channel Partners page. A partner is added from inside the
     | Add lead modal, at the moment somebody is logging the lead that came
     | through them, which is the only moment anybody actually knows the
     | broker's name and number.
     |
     | No `role:admin`, and it must not have one: a salesperson logging a broker
     | lead has to be able to name the broker. QuickChannelPartnerRequest
     | authorises on LeadPolicy::create — "may this user create a lead" — which
     | is the permission that decides whether they could have opened the form
     | this request comes from at all. A telecaller does not have `add_leads` by
     | default, so they get no Add lead button, no inline form, and a 403 here
     | if they post to it directly.
     |
     | Outside the admin group and above it, so the group's middleware cannot
     | creep onto it later by accident.
     */
    Route::post('/channel-partners/quick', [ChannelPartnerController::class, 'quickStore'])
        ->name('channel-partners.quick-store');

    /*
     | The near-match warning's server half, behind the same door.
     |
     | The inline form warns instantly from the active partners it was shipped;
     | this is what lets it also warn about a partner somebody switched off,
     | which the browser has no way to know about. Read-only, and it hands back
     | nothing the picker beside it does not already show — except the fact that
     | a switched-off row with that name exists, which is precisely the thing
     | the user needs to be told before adding a second one.
     */
    Route::post('/channel-partners/check-name', [ChannelPartnerController::class, 'checkName'])
        ->name('channel-partners.check-name');

    /*
     | READING the roster is for everyone. `auth` on the outer group is the whole
     | door — there is deliberately no `role:` here, because the list, the search,
     | the filters and the pagination answer the same question the report's
     | "By channel partner" grouping answers, which a telecaller already reads.
     | The lead form's broker picker offers the same roster to every role that
     | can file a lead, so hiding the page only made the picker a list nobody
     | could look at whole. ChannelPartnerPolicy::viewAny says the same thing in
     | the controller, which is the lock that survives somebody reorganising
     | this file.
     */
    Route::get('/channel-partners', [ChannelPartnerController::class, 'index'])
        ->name('channel-partners.index');

    /*
     | EDITING an existing partner is open to every signed-in role, on the same
     | door as reading it. `auth` is the whole gate here, deliberately: the name
     | and number the roster shows a telecaller are the same ones the page
     | offers them to fix, so correcting a typo in a label is not an admin
     | privilege. ChannelPartnerRequest authorises any authenticated user and
     | ChannelPartnerPolicy::update says so again in the controller.
     |
     | This is the modal's PUT — there is no edit GET, the form lives on the
     | roster page. It is edit only: creation is the route above and deletion is
     | the DELETE in the admin-only group below.
     */
    Route::put('/channel-partners/{partner}', [ChannelPartnerController::class, 'update'])
        ->name('channel-partners.update');

    /*
     | MERGING is open to every signed-in role too, on the same door as reading
     | and editing. It is the cleanup for what inline creation costs: three
     | people logging broker leads will enter "Shreeji" and "Shreeji Realty",
     | and the report is only worth reading if the person who finds the
     | duplicate can put it back together. The merge itself is unchanged — the
     | direction, the reattribution, the hierarchy rule — only who may start one
     | is wider. MergeChannelPartnerRequest still validates the shape, and
     | ChannelPartnerPolicy::merge says so again in the controller.
     */
    Route::post('/channel-partners/{partner}/merge', [ChannelPartnerController::class, 'merge'])
        ->name('channel-partners.merge');

    /*
     | DELETE stays admin-only, on the group rather than on the route, for
     | exactly the reason the users group says: a route added here later cannot
     | be forgotten. The middleware re-checks is_active as well, so a
     | deactivated admin's live session cannot delete.
     |
     | This is what keeps the edit-and-merge promise honest. The page draws the
     | Delete button only for an admin, but that is presentation and not the
     | boundary — a DELETE typed by hand gets a 403 from this middleware, and
     | destroy() says it yet again through ChannelPartnerPolicy::delete.
     |
     | Not permission-gated, and deliberately not. The five toggles in
     | config('crm.permissions') are about leads; who a company's channel
     | partners are is not one of them and must not become grantable from
     | inside the app.
     */
    Route::middleware('role:admin')->group(function () {
        Route::delete('/channel-partners/{partner}', [ChannelPartnerController::class, 'destroy'])
            ->name('channel-partners.destroy');
    });

    /* ---------------- pipeline: stages & sources (admin only) ---------------- */

    /*
     | The vocabulary every other page in the application speaks: the stages a
     | lead can stand in and the sources it can come from. These used to be two
     | arrays in config/crm.php that only a developer could change.
     |
     | Admin only, on the group rather than on each route, so a route added here
     | later cannot be forgotten — and this is the group where that matters
     | most. Every one of these writes reference data that `leads.stage`,
     | `leads.source` and `todos.outcome_stage` are matched against by string,
     | with no foreign key underneath to catch a mistake. A telecaller who types
     | /pipeline gets a 403 from the middleware; LeadStageRequest::authorize()
     | and LeadSourceRequest::authorize() say the same thing again, which is the
     | lock that survives somebody reorganising this file.
     |
     | Deliberately not permission-gated. The five toggles in
     | config('crm.permissions') are about who may touch LEADS; who decides what
     | a stage is called is not one of them and must not become grantable from
     | inside the app.
     |
     | THE DELETE ROUTES ARE HARD DELETES, and the only ones in the application
     | that are not soft. They are also the most refused: PipelineController
     | turns one down unless the row is a word nothing has ever been written in
     | — no leads, no history, no automation rule. Switching a stage off is the
     | operation an admin actually wants and it is a PUT, not a DELETE.
     */
    Route::middleware('role:admin')->prefix('pipeline')->group(function () {
        Route::get('/', [PipelineController::class, 'index'])->name('pipeline.index');

        Route::post('/stages', [PipelineController::class, 'storeStage'])
            ->name('pipeline.stages.store');
        Route::put('/stages/{stage}', [PipelineController::class, 'updateStage'])
            ->name('pipeline.stages.update');
        Route::delete('/stages/{stage}', [PipelineController::class, 'destroyStage'])
            ->name('pipeline.stages.destroy');
        /*
         | Above the {stage} routes would be a bug waiting for somebody to name
         | a stage "reorder"; below them it is unreachable for the same reason.
         | It is neither: `stages/order` cannot collide with `stages/{stage}`
         | because {stage} is bound by id, and a numeric segment never reads as
         | the word.
         */
        Route::post('/stages/order', [PipelineController::class, 'reorderStages'])
            ->name('pipeline.stages.reorder');

        Route::post('/sources', [PipelineController::class, 'storeSource'])
            ->name('pipeline.sources.store');
        Route::put('/sources/{source}', [PipelineController::class, 'updateSource'])
            ->name('pipeline.sources.update');
        Route::delete('/sources/{source}', [PipelineController::class, 'destroySource'])
            ->name('pipeline.sources.destroy');
        Route::post('/sources/order', [PipelineController::class, 'reorderSources'])
            ->name('pipeline.sources.reorder');
    });

    /* ---------------- alerts ---------------- */

    /*
     | Not admin-only, and it must not become admin-only. A telecaller is told
     | when their own follow-up is three days overdue, and the bell in the
     | header carries their unread count on every page — a page they could not
     | open would be a count pointing at a 403.
     |
     | There is no privacy boundary to draw here: an alert names its recipient
     | in `user_id` and Alert::for() matches on it. Whether somebody should ever
     | have been told about a lead was decided before the row was written, in
     | AlertService::raise(), which is the only place that can decide it — an
     | alert's title carries the lead's name, so hiding the row afterwards would
     | already be too late.
     */
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('/alerts/read-all', [AlertController::class, 'readAll'])->name('alerts.read-all');
    /*
     | POST, not a link with a query string. Clicking an alert both opens the
     | lead and stops the alert counting, and doing that in one request is what
     | stops the two disagreeing. Reading it writes `read_at` on the alert row
     | and touches nothing else — not the lead, not its stage, not its
     | follow-up.
     */
    Route::post('/alerts/{alert}/read', [AlertController::class, 'read'])->name('alerts.read');

    /* ---------------- automation (admin only) ---------------- */

    /*
     | Admin only, on the group so a route added here later cannot be forgotten.
     | This is a real boundary and not a tidy sidebar: what is behind it writes
     | rules that reassign leads, move stages and message customers, and it
     | holds the WhatsApp access token.
     |
     | A non-admin typing /automation gets a 403 from the middleware, not a
     | hidden link and an empty page. AutomationRuleRequest and
     | MessageTemplateRequest re-check `isAdmin()` on top, which is the lock
     | that survives somebody reorganising this file.
     */
    Route::middleware('role:admin')->group(function () {

        Route::get('/automation', [AutomationController::class, 'index'])->name('automation.index');
        Route::get('/automation/guide', [AutomationController::class, 'guide'])->name('automation.guide');

        /*
         | The Test button. A POST because it carries a rule that may not exist
         | yet — the whole value is seeing the blast radius while you are still
         | choosing the conditions — and read-only despite the verb: nothing on
         | this path reaches RuleEngine, ActionRunner or LeadFollowUpService.
         |
         | Above the {rule} routes so "match" can never be read as a rule id.
         */
        Route::post('/automation/rules/match', [AutomationRuleController::class, 'matches'])
            ->name('automation.rules.match');

        Route::post('/automation/rules', [AutomationRuleController::class, 'store'])
            ->name('automation.rules.store');
        Route::put('/automation/rules/{rule}', [AutomationRuleController::class, 'update'])
            ->name('automation.rules.update');
        /*
         | Switching a rule on is its own request, separate from saving it, so
         | that a new rule is always written in the off position and the
         | confirmation can show the match count first.
         */
        Route::post('/automation/rules/{rule}/toggle', [AutomationRuleController::class, 'toggle'])
            ->name('automation.rules.toggle');
        Route::delete('/automation/rules/{rule}', [AutomationRuleController::class, 'destroy'])
            ->name('automation.rules.destroy');

        Route::post('/automation/templates', [MessageTemplateController::class, 'store'])
            ->name('automation.templates.store');
        Route::put('/automation/templates/{template}', [MessageTemplateController::class, 'update'])
            ->name('automation.templates.update');
        Route::post('/automation/templates/{template}/toggle', [MessageTemplateController::class, 'toggle'])
            ->name('automation.templates.toggle');
        Route::delete('/automation/templates/{template}', [MessageTemplateController::class, 'destroy'])
            ->name('automation.templates.destroy');

        /*
         | The queue. `open` answers JSON with the wa.me link because the
         | browser has to open it in a new tab, which an Inertia redirect
         | cannot do; the other two are ordinary form posts.
         */
        Route::post('/automation/messages/{message}/open', [MessageQueueController::class, 'open'])
            ->name('automation.messages.open');
        Route::post('/automation/messages/{message}/send', [MessageQueueController::class, 'send'])
            ->name('automation.messages.send');
        Route::post('/automation/messages/{message}/cancel', [MessageQueueController::class, 'cancel'])
            ->name('automation.messages.cancel');

        Route::put('/automation/whatsapp', [AutomationController::class, 'updateWhatsApp'])
            ->name('automation.whatsapp.update');
    });

    /* ---------------- integrations (admin only) ---------------- */

    /*
     | Admin only, on the group so a route added here later cannot be forgotten.
     | This is a real boundary and not a tidy sidebar: the settings behind it
     | hold a page access token and an app secret, and the test-lead button
     | writes a lead.
     |
     | The webhook Meta actually calls is NOT here — it is stateless and public,
     | and lives in routes/webhooks.php.
     */
    Route::middleware('role:admin')->group(function () {
        Route::get('/integrations', [IntegrationController::class, 'index'])
            ->name('integrations.index');

        /*
         | Both of these take a provider, and both are constrained to the ones
         | that are actually built — the same constraint routes/webhooks.php
         | puts on Meta's endpoint, for the same reason.
         |
         | On the route rather than in the controller because the controller is
         | reached through a FormRequest: a PUT to /integrations/whatsapp would
         | otherwise be validated first and come back as "choose a project" for
         | a platform that has no settings to choose anything for. A stub is a
         | 404 whatever the body says.
         |
         | Instagram joins this list by flipping `built` in
         | config/integrations.php — there is no route to add.
         */
        $built = collect(config('integrations.providers'))
            ->filter(fn (array $meta) => $meta['built'])
            ->keys()
            ->all();

        Route::put('/integrations/{provider}', [IntegrationController::class, 'update'])
            ->whereIn('provider', $built)
            ->name('integrations.update');
        Route::post('/integrations/{provider}/test', [IntegrationController::class, 'test'])
            ->whereIn('provider', $built)
            ->name('integrations.test');
    });

    /* ---------------- my profile (everyone) ---------------- */

    /*
     | Your own row, and no id in the URL to make it anybody else's.
     | ProfileRequest refuses role, permissions, is_active and approval_status
     | if they are posted, so no role can promote itself from here.
     |
     | At /account, not /profile. The three Breeze routes that were at /profile
     | had no Vue page behind them, and DELETE /profile hard-deleted the
     | signed-in account after nothing but a password check — straight past
     | DeleteUserRequest and UserHandoverService, which exist so that a leaving
     | employee's leads and follow-ups are handed to somebody before the row
     | goes. That URL stays a 404, and there is no DELETE here: a user is
     | removed on the Users page or not at all.
     */
    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('/account', [AccountController::class, 'update'])->name('account.update');
});

require __DIR__.'/auth.php';
