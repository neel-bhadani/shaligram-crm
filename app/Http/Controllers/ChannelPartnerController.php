<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesFilters;
use App\Http\Requests\ChannelPartnerRequest;
use App\Http\Requests\MergeChannelPartnerRequest;
use App\Http\Requests\QuickChannelPartnerRequest;
use App\Models\ChannelPartner;
use App\Models\Lead;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Channel partners — the firms and brokers leads come through.
 *
 * THIS PAGE DOES NOT CREATE THEM. There is no store() below and no Add button
 * on the page, and that is the design rather than an omission: a partner is
 * created from inside the Add lead modal, at the moment somebody is logging the
 * lead that came through them, by quickStore() at the bottom of this file. A
 * second creation path here would be a second set of four fields that an admin
 * fills in from memory a day later, which is how a roster acquires rows nobody
 * ever attributes a lead to.
 *
 * So this screen manages what the lead flow produced: edit the details the
 * four-field form did not ask for, switch a partner off, delete one, and MERGE
 * the duplicates that creating rows mid-call inevitably produces.
 *
 * READING, EDITING and MERGING are for everyone. Index, update and merge sit
 * on the outer `auth` group rather than inside the admin group: the list, the
 * search, the filters and the broker picker on the lead form read this table
 * for every role, so logging a lead is no reason to lock who may fix a name or
 * a number — and the update and merge are the other half of that same cleanup.
 * ChannelPartnerPolicy::viewAny, ::update and ::merge say so again in this
 * controller, which is the lock that survives somebody reorganising
 * routes/web.php.
 *
 * DELETE is admin-only. It stays behind `role:admin` on the group in
 * routes/web.php, and destroy() is double-locked through
 * ChannelPartnerPolicy::delete so a DELETE typed by hand is refused even
 * around the middleware. A non-admin is never shown the delete button, but
 * that is presentation and not the boundary.
 *
 * quickStore() is a different door for a different question — the Add lead
 * modal's inline form, gated like the lead it belongs to. See
 * QuickChannelPartnerRequest.
 *
 * FIRMS AND BROKERS ARE NOT GROUPED IN THE TABLE. They could have been —
 * a firm heading with its brokers indented under it — and it was the wrong
 * choice for this list for three reasons:
 *
 *   the list is paginated, and a group that straddles page 15/16 is a heading
 *   on one page and orphaned rows on the next;
 *
 *   grouping fights every other control on the bar. Search for a name, or
 *   filter to inactive, and the groups become headings with one row under
 *   them and headings with none;
 *
 *   an individual broker belongs to no firm at all, so a grouped table needs
 *   a fourth heading meaning "no firm", which is a filter wearing a costume.
 *
 * So the relationship is a COLUMN and a FILTER instead. Every broker row names
 * its firm in the Parent firm column, every firm row says how many brokers it
 * holds, and that count is a link: clicking it filters the list to that firm's
 * brokers. The same answer, composable with search and status, and it survives
 * pagination.
 */
class ChannelPartnerController extends Controller
{
    use ResolvesFilters;

    public function index(Request $request)
    {
        // reading the roster is open to every signed-in user (the route is on
        // the `auth` group, not the admin one); this is the lock that survives
        // somebody moving the route back into the group later
        $this->authorize('viewAny', ChannelPartner::class);

        $user    = $request->user();
        $filters = $this->filters($request);

        $partners = ChannelPartner::query()
            // the Parent firm column, and the appended display_label that
            // reads it — without this the page is a query a row
            ->with('parent:id,name')
            ->when($filters['search'] ?? null, function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->where('name', 'like', "%$s%")
                        ->orWhere('contact_person', 'like', "%$s%")
                        ->orWhere('phone', 'like', "%$s%")
                        ->orWhere('alt_phone', 'like', "%$s%")
                        ->orWhere('email', 'like', "%$s%")
                        // "show me Shreeji Realty and everyone under it"
                        ->orWhereHas('parent', fn ($p) => $p->where('name', 'like', "%$s%"));
                });
            })
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when(
                // 'all' is absence; the two real values are strings because
                // that is what a <select> sends
                isset($filters['status']),
                fn ($q) => $q->where('is_active', $filters['status'] === 'active')
            )
            // "brokers of this firm" — the grouped view, as a filter
            ->when($filters['parent_id'] ?? null, fn ($q, $v) => $q->where('parent_id', $v))
            ->withCount([
                /*
                 | The number this whole screen exists to make visible, and the
                 | one the delete dialog reads before it asks.
                 |
                 | visibleTo() even though the page is admin-only and an admin
                 | resolves `see_all_leads` true, so the scope is a no-op here
                 | today. It is on every lead query in this application without
                 | exception, and a count that quietly skipped it would be the
                 | one place the rule did not hold the day this page is opened
                 | up to a sales manager.
                 |
                 | Lifetime, not open leads: "how much business has this broker
                 | ever brought" is the question, and a booked lead is the
                 | answer to it rather than a row that has stopped counting.
                 */
                'leads as leads_count' => fn ($q) => $q->visibleTo($user),

                /*
                 | Bookings, counted the way every other booking in this
                 | application is counted: a lead with a COMPLETED to-do whose
                 | `outcome_stage` is booking_done. Never `leads.stage`, which
                 | says where a lead stands now and would miss one that booked
                 | and was later edited.
                 |
                 | Counting LEADS that have such a to-do rather than the to-dos
                 | themselves is what makes it distinct by lead for free — a
                 | lead with two booking rows on it is one booking, which is the
                 | same rule ReportController counts by.
                 |
                 | No date window: this column is "what has this partner ever
                 | brought", and the Reports page is where the same figure is
                 | asked over a period.
                 */
                'leads as bookings_count' => fn ($q) => $q->visibleTo($user)
                    ->whereHas('todos', fn ($t) => $t
                        ->where('status', 'completed')
                        ->where('outcome_stage', 'booking_done')),

                // firms only in practice; the two the delete guard compares
                'brokers as brokers_count',
                'brokers as active_brokers_count' => fn ($q) => $q->active(),
            ])
            /*
             | Firms first, then brokers, each alphabetically. Not a grouping —
             | see the class docblock — but it does mean the firms an admin is
             | most likely to be looking for are not scattered through a list of
             | fifty broker names.
             */
            ->orderByRaw("CASE type WHEN 'firm' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->paginate(15)
            ->through(fn (ChannelPartner $p) => [
                'id'             => $p->id,
                'name'           => $p->name,
                'display_label'  => $p->display_label,
                'type'           => $p->type,
                'parent_id'      => $p->parent_id,
                'parent_name'    => $p->parent?->name,
                'contact_person' => $p->contact_person,
                'phone'          => $p->phone,
                'alt_phone'      => $p->alt_phone,
                'email'          => $p->email,
                'address'        => $p->address,
                'is_active'      => $p->is_active,
                'leads_count'          => $p->leads_count,
                'bookings_count'       => $p->bookings_count,
                'brokers_count'        => $p->brokers_count,
                'active_brokers_count' => $p->active_brokers_count,
            ]);

        return Inertia::render('ChannelPartners/Index', [
            'partners' => $partners,
            'filters'  => $filters,
            'options'  => [
                'types' => config('crm.channel_partner_types'),
                /*
                 | Active firms, for the modal's parent dropdown and for the
                 | "brokers of this firm" filter. The same list the `exists`
                 | rule in ChannelPartnerRequest checks against, so the control
                 | cannot offer something the rule refuses.
                 |
                 | A firm that has been switched off is deliberately absent: new
                 | brokers should not be filed under it. Brokers already under
                 | it keep pointing at it and keep their label.
                 */
                'firms' => ChannelPartner::firms()->active()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn ($f) => ['id' => $f->id, 'name' => $f->name]),

                /*
                 | Every live, active partner, for the merge dialog's target
                 | picker — firms and brokers alike, because a firm entered once
                 | as a broker is exactly the duplicate merge exists to clean
                 | up. The whole roster rather than the current page of it: the
                 | row being merged away and the row it is merging into are very
                 | often not on the same page of a paginated list.
                 |
                 | The same active-only rule MergeChannelPartnerRequest checks
                 | on the way back in, so the control cannot offer a target the
                 | rule refuses.
                 */
                'mergeTargets' => ChannelPartner::active()
                    ->with('parent:id,name')
                    ->orderByRaw("CASE type WHEN 'firm' THEN 0 ELSE 1 END")
                    ->orderBy('name')
                    ->get(['id', 'name', 'type', 'parent_id'])
                    ->map(fn (ChannelPartner $p) => [
                        'id'    => $p->id,
                        'label' => $p->display_label,
                        'type'  => $p->type,
                    ]),
            ],
        ]);
    }

    /** The filters this page owns. No defaults: the whole roster is the start. */
    private function filters(Request $request): array
    {
        return $this->resolveFilters(
            $request,
            'channel-partners',
            [
                'search'    => ['sometimes', 'string', 'max:100'],
                'type'      => ['sometimes', 'string', Rule::in(array_keys(config('crm.channel_partner_types')))],
                'status'    => ['sometimes', 'string', 'in:active,inactive'],
                'parent_id' => ['sometimes', 'integer', 'min:1'],
            ],
        );
    }

    /**
     * Create a partner from inside the Add lead modal, and hand it straight
     * back so the form can select it.
     *
     * JSON over axios rather than an Inertia visit, and that is the whole
     * reason this reads differently from every other write in the application.
     * An Inertia POST re-renders the page it lands on; the user is halfway
     * through typing a lead, and re-rendering underneath them would either lose
     * what they have typed or need the whole form's state carried through a
     * redirect to survive. A fetch changes nothing on the page but the one
     * select, which is the only thing that should change.
     *
     * NO TRANSACTION WITH THE LEAD, deliberately. The partner is committed the
     * moment it is created and it stays committed if the lead that prompted it
     * then fails validation — the user corrects the phone number and submits
     * again with the partner still selected, rather than finding the broker
     * they just added has vanished. The two are separate decisions that happen
     * to be made in one sitting: the partner is a fact about the world, the
     * lead is a fact about a conversation, and rolling the first back because
     * the second was mistyped would be the form punishing a typo by discarding
     * something correct.
     *
     * The orphan case — a partner created and the lead abandoned — is a row on
     * the Channel Partners page with a lead count of 0, which an admin can see,
     * merge or delete. That is a far cheaper failure than the alternative.
     */
    public function quickStore(QuickChannelPartnerRequest $request)
    {
        try {
            $partner = ChannelPartner::create($request->channelPartnerAttributes());
        } catch (UniqueConstraintViolationException $e) {
            /*
             | The unique index on (name_key, type) is the real guarantee, and
             | the rule in ValidatesChannelPartner checks the same rows it does
             | — so reaching here means the narrow gap between that check and
             | the insert: two people adding the same broker at the same moment.
             | The index wins, and the caller gets the row that won rather than
             | a 500 in the middle of logging a lead.
             */
            $clash = ChannelPartner::findClash($request->input('name'), $request->input('type'));

            return response()->json([
                'message' => $clash
                    ? "\"{$clash->name}\" was just added by somebody else. Use that one."
                    : 'That partner already exists.',
                'partner' => $clash ? $this->partnerOption($clash) : null,
            ], 409);
        }

        $partner->load('parent:id,name');

        return response()->json(['partner' => $this->partnerOption($partner)], 201);
    }

    /**
     * Near-matches for a name being typed into the inline form, as the user
     * types it.
     *
     * DEFENCE TWO of three, and the half of it the browser cannot do.
     *
     * The inline form warns instantly from the list it was shipped — see
     * lib/partnerName.js, which is the mirror of the model's two normalisers —
     * and that list is the ACTIVE partners. So a name that closely matches a
     * partner somebody switched off last month raises no warning on screen, the
     * user adds a second row, and the roster grows the exact duplicate this
     * feature is trying to prevent. Only the server can see those rows.
     *
     * So the instant warning stays and this layers over it: same shape, same
     * normalisation, wider population. The same arrangement LeadFormModal
     * already uses for a duplicate mobile number, for the same reason — the
     * check that can see everything is the one that has to be a round trip.
     *
     * A warning, never a refusal. Two genuinely different firms can share a
     * first word, and the person on the phone knows which one they are talking
     * to. The unique index is where "no" gets said.
     */
    public function checkName(Request $request)
    {
        // the same door the inline form itself is behind: may this user create
        // a lead? A telecaller cannot, so they cannot enumerate the roster here
        $this->authorize('create', Lead::class);

        $request->validate(['name' => ['required', 'string', 'max:150']]);

        return response()->json([
            'matches' => ChannelPartner::nearMatches($request->input('name'))
                ->map(fn (ChannelPartner $p) => $this->partnerOption($p) + [
                    // an inactive match cannot be selected on the lead form, so
                    // the warning says what to do about it instead of offering
                    // a button that would fail validation
                    'is_active' => $p->is_active,
                ])
                ->values(),
        ]);
    }

    /** The shape the lead form's picker expects — see LeadController::options(). */
    private function partnerOption(ChannelPartner $partner): array
    {
        return [
            'id'    => $partner->id,
            'name'  => $partner->name,
            'label' => $partner->display_label,
            'type'  => $partner->type,
        ];
    }

    public function update(ChannelPartnerRequest $request, ChannelPartner $partner)
    {
        // editing is open to every signed-in role, on the same `auth` group as
        // the page itself; this is the second lock, the one that survives
        // somebody moving the route back into the admin group
        $this->authorize('update', $partner);

        $partner->update($request->channelPartnerAttributes());

        return back()->with('success', 'Channel partner updated.');
    }

    /**
     * Soft delete, and the one refusal that comes with it.
     *
     * A firm still holding live brokers is not removed. Those brokers would be
     * left pointing at a deleted parent: their "Ravi Kumar — Shreeji Realty"
     * label loses the half that tells two Ravis apart, and the modal's parent
     * dropdown would no longer offer the firm they are filed under, so nothing
     * on screen could put them right again. The admin is told the number and
     * what to do about it.
     *
     * Deactivated brokers are not in the way. Switching a firm's brokers off is
     * one of the two things this message asks for, so counting them would make
     * the instruction impossible to follow.
     *
     * The leads are never in the way. `leads.channel_partner_id` nulls only if
     * a row is hard-deleted straight from the database, which nothing here
     * does — a soft-deleted partner keeps every lead pointing at it, and the
     * report keeps counting them under a name marked "(removed)". That is why
     * the dialog states the lead count rather than blocking on it: it is
     * information, not an obstacle.
     */
    /**
     * Merge one partner into another: every lead moves, then the source is soft
     * deleted.
     *
     * This is the cleanup for what inline creation costs. Partners are created
     * mid-call by whoever is logging the lead, so "Shreeji", "Shreeji Realty"
     * and "Shreeji Realty Pvt Ltd" will all appear — the typeahead and the
     * near-match warning catch most of it, the unique index catches the exact
     * repeats, and neither can catch a person who genuinely believes the firm
     * they are typing is a new one. Without this, the broker report degrades
     * into an unreadable list of near-identical names within a month, which is
     * the failure the whole feature exists to avoid.
     *
     * FOUR THINGS MOVE, and they are one transaction because a half-done merge
     * is worse than no merge: leads split across two rows that now claim to be
     * the same partner, with no record of which were meant to move.
     *
     *   the leads          `channel_partner_id` is repointed at the target.
     *                      `broker_name` is not touched — a lead that carried
     *                      free text from before this feature keeps carrying
     *                      it, and the fallback only reads it when the FK is
     *                      null, which after this it is not.
     *
     *   the brokers        a firm's brokers move with it, or they would be left
     *                      pointing at a soft-deleted parent and lose the half
     *                      of "Ravi Kumar — Shreeji Realty" that tells two
     *                      Ravis apart. MergeChannelPartnerRequest refuses the
     *                      case where they would land under a broker.
     *
     *   the source's name  freed, because the soft delete nulls `name_key` —
     *                      see ChannelPartner::booted(). Somebody re-entering
     *                      that spelling tomorrow gets a new row rather than a
     *                      unique-index failure they cannot act on.
     *
     *   the source itself  soft deleted, never hard. Nothing in this
     *                      application hard deletes a partner, and here least
     *                      of all: the row is the audit trail for a bulk
     *                      reattribution.
     *
     * The lead update is a bulk UPDATE rather than a loop of saves. It is one
     * statement inside the transaction, it does not need model events — nothing
     * listens for a lead's partner changing — and a broker with four hundred
     * leads would otherwise be four hundred round trips holding a transaction
     * open.
     *
     * NO visibleTo() ON THE MOVE, and that is the one place in this application
     * where a lead query deliberately has none. The scope answers "which leads
     * may this user SEE"; this is not a read. Scoping it would move the leads
     * the admin happens to be able to see and silently leave the rest pointing
     * at a row that no longer exists on any screen — which is exactly the
     * split-attribution this method exists to repair. The route is admin-only
     * and MergeChannelPartnerRequest says so again, so the boundary is the door
     * rather than the query.
     */
    public function merge(MergeChannelPartnerRequest $request, ChannelPartner $partner)
    {
        // merging is open to every signed-in role, on the same `auth` group as
        // the page itself; this is the second lock, the one that survives
        // somebody moving the route back into the admin group
        $this->authorize('merge', $partner);

        $target = ChannelPartner::findOrFail($request->input('target_id'));

        $moved = DB::transaction(function () use ($partner, $target) {
            $leads = Lead::withTrashed()
                ->where('channel_partner_id', $partner->id)
                ->update(['channel_partner_id' => $target->id]);

            // a firm's brokers follow it; a broker has none, so this is a no-op
            $brokers = ChannelPartner::where('parent_id', $partner->id)
                ->update(['parent_id' => $target->isFirm() ? $target->id : null]);

            $partner->delete();

            return ['leads' => $leads, 'brokers' => $brokers];
        });

        /*
         | withTrashed() on the lead update above, and it is not an oversight: a
         | soft-deleted lead still carries its attribution, an admin may restore
         | it from the database, and leaving it pointing at a merged-away row
         | would be the one lead in the table that disagreed with the rest.
         */
        $notice = "{$moved['leads']} lead" . ($moved['leads'] === 1 ? '' : 's')
            . " moved to {$target->name}."
            . ($moved['brokers'] > 0
                ? " {$moved['brokers']} broker" . ($moved['brokers'] === 1 ? '' : 's') . ' moved with it.'
                : '');

        return back()
            ->with('success', "\"{$partner->name}\" merged into \"{$target->name}\".")
            ->with('warning', $notice);
    }

    public function destroy(ChannelPartner $partner)
    {
        // admin-only, twice over: `role:admin` on the route group, and again here
        // so a plain DELETE typed by hand is refused even if the group is ever
        // reorganised. A non-admin hiding the button is not the boundary — this is.
        $this->authorize('delete', $partner);

        $active = $partner->activeBrokers()->count();

        if ($partner->isFirm() && $active > 0) {
            throw ValidationException::withMessages([
                'partner' => "This firm has {$active} active broker" . ($active === 1 ? '' : 's')
                    . ' filed under it. Reassign or deactivate them first.',
            ]);
        }

        $partner->delete();

        return back()->with('success', 'Channel partner deleted.');
    }
}
