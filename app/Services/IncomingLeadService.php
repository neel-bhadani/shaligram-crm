<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A lead arriving from a machine rather than from a person at a keyboard.
 *
 * Everything provider-specific has already happened by the time this is called
 * — the Graph fetch, the field-name guessing, the phone stripping — so what is
 * left is the part that must be identical to the Leads page: create the row,
 * then hand it to LeadFollowUpService for its first follow-up, both inside one
 * transaction. That is exactly the shape of LeadController::store(), and it is
 * the reason an imported lead cannot break the invariant the To-do page rests
 * on.
 *
 * The two outcomes that are not "created" live here too, because both are
 * decisions about whether a lead should exist and neither is Meta's business:
 * a redelivered external_id, and a phone number already enquiring about this
 * project.
 */
class IncomingLeadService
{
    public function __construct(
        private LeadFollowUpService $followUps,
        private LeadAssignmentService $assignment,
    ) {}

    /** Where every imported lead starts: nobody has spoken to them yet. */
    private const STAGE = 'fresh';

    /** @var string returned by import() when the same external_id arrived before */
    public const DUPLICATE = 'duplicate';

    /** @var string returned when this number is already enquiring about this project */
    public const REPEAT = 'repeat_enquiry';

    /**
     * Create the lead and its first follow-up, or say why not.
     *
     * @param  array{first_name: string, last_name: string, mobile_number: string, email: ?string}  $attributes
     * @return array{result: string, lead: ?Lead, message: string}
     */
    public function import(Integration $integration, string $externalId, array $attributes, string $source): array
    {
        $projectId = (int) $integration->setting('default_project_id');
        $holder = User::find($integration->setting('assign_to_user_id'));

        if (! $holder) {
            // configured against somebody who has since been deleted: a lead
            // with no owner would be invisible on every list in the application
            throw new \RuntimeException('The user this integration assigns leads to no longer exists.');
        }

        /*
         | The same person, enquiring again.
         |
         | Not an error: they have filled in a second form, perhaps from a
         | different ad. But they already have a lead on this project with an
         | owner and a pending follow-up, and a second row would split one
         | conversation in two and put the same customer on two people's call
         | lists.
         |
         | The form may add the same number twice on purpose; this path may
         | not. Nothing in the schema enforces it any more, so the check and
         | the insert run under one lock per number and project — two
         | different enquiries from the same person arriving at once are still
         | one lead and one repeat, as they were when the index refused the
         | second. The external_id check runs inside it as well, so a
         | redelivery that waited on the lock is still called a duplicate.
         */
        return Cache::lock("incoming-lead:{$projectId}:{$attributes['mobile_number']}", 30)
            ->block(10, fn () => $this->importOnce($externalId, $attributes, $projectId, $holder, $source));
    }

    /**
     * The repeat check and the insert, run while import() holds the lock.
     *
     * @param  array{first_name: string, last_name: string, mobile_number: string, email: ?string}  $attributes
     * @return array{result: string, lead: ?Lead, message: string}
     */
    private function importOnce(string $externalId, array $attributes, int $projectId, User $holder, string $source): array
    {
        /*
         | Idempotency, first pass.
         |
         | withTrashed(), because `leads.external_id` is unique across deleted
         | rows too — a lead that was imported and then deleted must not come
         | back on Meta's next retry, and an insert that ignored the trashed row
         | would fail on the index rather than skip.
         */
        $existing = Lead::withTrashed()->where('external_id', $externalId)->first();

        if ($existing) {
            return [
                'result' => self::DUPLICATE,
                'lead' => $existing,
                'message' => "Already imported as lead #{$existing->id}; nothing created.",
            ];
        }

        $repeat = Lead::withTrashed()
            ->where('mobile_number', $attributes['mobile_number'])
            ->where('project_id', $projectId)
            ->first();

        if ($repeat) {
            return [
                'result' => self::REPEAT,
                'lead' => $repeat,
                'message' => $repeat->trashed()
                    ? "This number is on deleted lead #{$repeat->id} for this project. Restore that lead rather than importing it again."
                    : "This number is already lead #{$repeat->id} on this project — a repeat enquiry, not a new lead.",
            ];
        }

        try {
            $lead = DB::transaction(function () use ($attributes, $externalId, $projectId, $holder, $source) {
                /*
                 | Routed like every other new lead: by its stage and project,
                 | through the same LeadAssignmentService the Leads page and the
                 | handover use. The integration's configured user is the
                 | holder — they keep it when they are on the stage's desk, and
                 | it falls back to them when that desk is empty — but a `fresh`
                 | lead goes to a telecaller whoever was configured, because
                 | calling it is the only work it has.
                 |
                 | Asked here, after the two early returns and inside the
                 | transaction, so neither a redelivery nor a concurrent one the
                 | external_id index refuses takes a turn from a round robin.
                 */
                $owner = $this->assignment->ownerFor(self::STAGE, $holder, $projectId);

                $lead = Lead::create($attributes + [
                    'project_id' => $projectId,
                    'source' => $source,
                    'external_id' => $externalId,
                    'stage' => self::STAGE,
                    'assigned_to' => $owner->id,
                    'assigned_role' => $owner->role,
                    // nobody typed this in; created_by is nullable and stays
                    // null, which is what marks a lead as machine-created
                    'created_by' => null,
                    'stage_changed_at' => now(),
                    'last_activity_at' => now(),
                ]);

                /*
                 | The follow-up, through the service that owns them.
                 |
                 | Scheduling is manual everywhere else in this application —
                 | the user types a date on the form — so an imported lead has
                 | nobody to type one and would land with no pending task at
                 | all. That is not a cosmetic gap: an open lead with no pending
                 | to-do appears on no tab of the To-do page, in no dashboard
                 | panel and on nobody's list, and would never be called.
                 |
                 | now() puts it in the assigned user's Due today, which is the
                 | correct urgency for a lead that has this second raised its
                 | hand on an advert.
                 */
                $this->followUps->onLeadCreated(
                    $lead,
                    now(),
                    'call',
                    'Lead arrived from '.config("integrations.providers.$source.name", $source).'. Call as soon as possible.',
                );

                return $lead;
            });
        } catch (UniqueConstraintViolationException $e) {
            /*
             | Idempotency, second pass — and the one that actually holds.
             |
             | The checks above are a read followed by a write, so two
             | deliveries of the same leadgen_id arriving at once can both pass
             | them. The unique index is what makes the guarantee, and this is
             | where that refusal is turned back into "duplicate" rather than a
             | failed job Meta would retry forever.
             */
            return [
                'result' => self::DUPLICATE,
                'lead' => Lead::withTrashed()->where('external_id', $externalId)->first(),
                'message' => 'A concurrent delivery created this lead first; nothing created.',
            ];
        }

        return ['result' => 'created', 'lead' => $lead, 'message' => "Lead #{$lead->id} created."];
    }
}
