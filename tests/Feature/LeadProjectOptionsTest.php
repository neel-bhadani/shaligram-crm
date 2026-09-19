<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Project dropdown on the add/edit-lead form — see
 * LeadController::visibleProjects().
 *
 * A salesperson without `see_all_leads` is narrowed to the project(s) they
 * are tied to via `project_user`, the same relationship the reassign
 * candidate list and Lead::scopeVisibleTo() already read — see
 * LeadReassignmentTest for that same boundary checked on the reassign side.
 * Admin, and a salesperson granted `see_all_leads`, are exempt from the
 * boundary entirely, same as everywhere else it is checked.
 */
class LeadProjectOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_salesperson_is_offered_only_the_projects_they_are_tied_to(): void
    {
        $sam = $this->user('salesperson');
        $alpha = $this->projectWith('Alpha', $sam);
        $this->projectWith('Beta');

        $this->actingAs($sam)
            ->get('/leads')
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.projects', [['id' => $alpha->id, 'name' => 'Alpha']]));
    }

    public function test_a_salesperson_on_two_projects_is_offered_both_and_nothing_else(): void
    {
        $sam = $this->user('salesperson');
        $alpha = $this->projectWith('Alpha', $sam);
        $pqr = $this->projectWith('PQR', $sam);
        $this->projectWith('Beta');

        $this->actingAs($sam)
            ->get('/leads')
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.projects', [
                    ['id' => $alpha->id, 'name' => 'Alpha'],
                    ['id' => $pqr->id, 'name' => 'PQR'],
                ]));
    }

    public function test_a_salesperson_assigned_to_no_project_is_offered_an_empty_dropdown(): void
    {
        $sam = $this->user('salesperson');
        $this->projectWith('Alpha');

        $this->actingAs($sam)
            ->get('/leads')
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.projects', []));
    }

    public function test_admin_is_offered_every_active_project_unrestricted(): void
    {
        $admin = $this->user('admin');
        $sam = $this->user('salesperson');
        $alpha = $this->projectWith('Alpha', $sam);
        $beta = $this->projectWith('Beta');

        $this->actingAs($admin)
            ->get('/leads')
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.projects', [
                    ['id' => $alpha->id, 'name' => 'Alpha'],
                    ['id' => $beta->id, 'name' => 'Beta'],
                ]));
    }

    /** A sales manager — a salesperson granted `see_all_leads` — is exempt from the boundary, same as admin. */
    public function test_a_salesperson_with_see_all_leads_is_offered_every_active_project(): void
    {
        $manager = $this->user('salesperson', permissions: ['see_all_leads' => true]);
        $alpha = $this->projectWith('Alpha');
        $beta = $this->projectWith('Beta', $manager);

        $this->actingAs($manager)
            ->get('/leads')
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.projects', [
                    ['id' => $alpha->id, 'name' => 'Alpha'],
                    ['id' => $beta->id, 'name' => 'Beta'],
                ]));
    }

    public function test_an_inactive_project_never_appears_for_admin_or_salesperson(): void
    {
        $admin = $this->user('admin');
        $sam = $this->user('salesperson');
        $alpha = $this->projectWith('Alpha', $sam);
        $this->projectWith('Beta', $sam)->update(['is_active' => false]);

        $this->actingAs($sam)
            ->get('/leads')
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.projects', [['id' => $alpha->id, 'name' => 'Alpha']]));

        $this->actingAs($admin)
            ->get('/leads')
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.projects', [['id' => $alpha->id, 'name' => 'Alpha']]));
    }

    private function projectWith(string $name, User ...$salespeople): Project
    {
        $project = Project::create(['name' => $name]);
        $project->salespeople()->attach(array_map(fn (User $u) => $u->id, $salespeople));

        return $project;
    }

    private function user(string $role, array $permissions = []): User
    {
        return User::create([
            'first_name' => 'Test',
            'last_name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role,
            'is_active' => true,
            'password' => 'password',
            'permissions' => $permissions,
        ]);
    }
}
