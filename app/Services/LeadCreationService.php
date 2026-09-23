<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** The transactional creation path shared by the manual form and bulk import. */
class LeadCreationService
{
    public function __construct(private LeadAssignmentService $assignment, private LeadFollowUpService $followUps) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, User $creator, User $holder, ?Carbon $when, ?string $type, ?string $remarks): Lead
    {
        return DB::transaction(function () use ($attributes, $creator, $holder, $when, $type, $remarks): Lead {
            $owner = $this->assignment->ownerFor($attributes['stage'], $holder, (int) $attributes['project_id']);
            $lead = Lead::create($attributes + [
                'assigned_to' => $owner->id,
                'assigned_role' => $owner->role,
                'created_by' => $creator->id,
                'stage_changed_at' => now(),
                'last_activity_at' => now(),
            ]);
            $this->followUps->onLeadCreated($lead, $when, $type, $remarks);

            return $lead;
        });
    }
}
