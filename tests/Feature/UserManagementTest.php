<?php

namespace Tests\Feature;

use App\Http\Controllers\UserController;
use App\Http\Requests\Concerns\HandsOverWork;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\UserHandoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The admin's user management screen, end to end.
 *
 * Three things are being protected here and they are not the same thing:
 *
 *   the door       every route is admin-only, and typing the URL is not a way
 *                  around it.
 *
 *   the history    a user is never really deleted. `todos.assigned_to` is NOT
 *                  NULL behind a cascading foreign key and completed to-dos
 *                  record who made each call, so a hard delete would take the
 *                  numbers behind every chart with it.
 *
 *   the invariant  Lead::open()->doesntHave('pendingTodo')->count() === 0,
 *                  which has to survive a handover in either direction. It is
 *                  asserted after every one of them below.
 *
 * @see UserController
 * @see UserHandoverService
 * @see HandsOverWork
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'Ann');
        $this->project = Project::create(['name' => 'Alpha']);

        Carbon::setTestNow(Carbon::parse('2026-09-02 14:03'));
    }

    /* ---------------- the door ---------------- */

    public function test_a_non_admin_hitting_the_user_urls_directly_gets_403(): void
    {
        foreach (['telecaller', 'salesperson'] as $role) {
            $staff = $this->user($role, ucfirst($role));

            $this->actingAs($staff)->get('/users')->assertForbidden();
            $this->actingAs($staff)->post('/users', [])->assertForbidden();
            $this->actingAs($staff)->put("/users/{$this->admin->id}", [])->assertForbidden();
            $this->actingAs($staff)->delete("/users/{$this->admin->id}")->assertForbidden();
        }
    }

    public function test_the_admin_can_open_the_page(): void
    {
        $this->actingAs($this->admin)->get('/users')->assertOk();
    }

    /* ---------------- adding ---------------- */

    public function test_the_admin_can_create_a_telecaller_and_a_salesperson(): void
    {
        foreach (['telecaller', 'salesperson'] as $i => $role) {
            $this->actingAs($this->admin)
                ->post('/users', $this->payload(['role' => $role, 'email' => "new$i@example.test"]))
                ->assertRedirect()->assertSessionHasNoErrors();

            $made = User::where('email', "new$i@example.test")->firstOrFail();

            $this->assertSame($role, $made->role);
            $this->assertTrue($made->is_active);
            // hashed by the model cast, never stored as typed
            $this->assertNotSame('secret123', $made->password);
            $this->assertTrue(Hash::check('secret123', $made->password));
        }
    }

    /** The screen hands out two roles. Admin is made in the seeder, not here. */
    public function test_the_admin_cannot_create_another_admin(): void
    {
        $this->actingAs($this->admin)
            ->post('/users', $this->payload(['role' => 'admin']))
            ->assertSessionHasErrors('role');

        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_a_short_or_unconfirmed_password_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/users', $this->payload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');

        $this->actingAs($this->admin)
            ->post('/users', $this->payload(['password_confirmation' => 'different1']))
            ->assertSessionHasErrors('password');
    }

    public function test_email_and_mobile_must_be_unique(): void
    {
        $existing = $this->user('telecaller', 'Tara');

        $this->actingAs($this->admin)
            ->post('/users', $this->payload(['email' => $existing->email]))
            ->assertSessionHasErrors('email');

        $this->actingAs($this->admin)
            ->post('/users', $this->payload(['mobile_number' => $existing->mobile_number]))
            ->assertSessionHasErrors('mobile_number');
    }

    /* ---------------- editing ---------------- */

    /** The case an admin hits constantly: fixing a surname, not a password. */
    public function test_editing_without_a_password_leaves_the_password_alone(): void
    {
        $staff = $this->user('telecaller', 'Tara');
        $before = $staff->password;

        $this->actingAs($this->admin)
            ->put("/users/{$staff->id}", $this->editPayload($staff, ['last_name' => 'Iyer']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $staff->refresh();

        $this->assertSame('Iyer', $staff->last_name);
        $this->assertSame($before, $staff->password, 'a blank password field must change nothing');
        $this->assertTrue(Hash::check('password', $staff->password), 'and the old one still works');
    }

    public function test_a_supplied_password_is_changed_and_hashed_once(): void
    {
        $staff = $this->user('telecaller', 'Tara');

        $this->actingAs($this->admin)
            ->put("/users/{$staff->id}", $this->editPayload($staff, [
                'password' => 'brandnew123', 'password_confirmation' => 'brandnew123',
            ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('brandnew123', $staff->fresh()->password));
    }

    public function test_uniqueness_ignores_the_user_being_edited(): void
    {
        $staff = $this->user('telecaller', 'Tara');

        $this->actingAs($this->admin)
            ->put("/users/{$staff->id}", $this->editPayload($staff))
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    /* ---------------- permissions ---------------- */

    public function test_an_untouched_user_falls_back_to_the_role_default(): void
    {
        $tele = $this->user('telecaller', 'Tara');
        $sales = $this->user('salesperson', 'Sam');

        $this->assertNull($tele->permissions, 'nothing is written until it is set');

        $this->assertFalse($tele->can_('add_leads'));
        $this->assertTrue($sales->can_('add_leads'));
        $this->assertFalse($sales->can_('delete_leads'));
        $this->assertTrue($this->admin->can_('see_all_leads'));
        // an unknown key closes the door rather than opening it
        $this->assertFalse($sales->can_('invent_money'));
    }

    /**
     * Null means "follows the role", so an ordinary edit must leave it null.
     * Otherwise fixing a surname would quietly pin that person to a snapshot
     * of today's defaults and detach them from their role for good.
     */
    public function test_an_edit_that_changes_nothing_leaves_the_user_on_role_defaults(): void
    {
        $tele = $this->user('telecaller', 'Tara');

        $this->actingAs($this->admin)
            ->put("/users/{$tele->id}", $this->editPayload($tele, [
                'last_name' => 'Iyer',
                // the modal always resubmits the resolved set, defaults and all
                'permissions' => $tele->effectivePermissions(),
            ]))->assertSessionHasNoErrors();

        $this->assertNull($tele->fresh()->permissions, 'still following the role');
    }

    /** Changing role re-bases which defaults "unset" means. */
    public function test_promoting_a_telecaller_gives_them_the_salesperson_defaults(): void
    {
        $tele = $this->user('telecaller', 'Tara');

        $this->actingAs($this->admin)
            ->put("/users/{$tele->id}", $this->editPayload($tele, [
                'role' => 'salesperson',
                'permissions' => config('crm.permission_defaults.salesperson'),
            ]))->assertSessionHasNoErrors();

        $tele->refresh();

        $this->assertNull($tele->permissions);
        $this->assertTrue($tele->can_('add_leads'), 'now a salesperson, by default');
    }

    public function test_an_explicit_toggle_overrides_the_role_default(): void
    {
        $tele = $this->user('telecaller', 'Tara');

        $this->actingAs($this->admin)
            ->put("/users/{$tele->id}", $this->editPayload($tele, [
                'permissions' => ['add_leads' => true],
            ]))->assertRedirect()->assertSessionHasNoErrors();

        $tele->refresh();

        $this->assertTrue($tele->can_('add_leads'), 'the granted one is on');
        $this->assertFalse($tele->can_('edit_leads'), 'the rest stay off');
    }

    /**
     * The toggle that changes a query rather than a button, which is why it is
     * tested against real rows rather than against can_().
     */
    public function test_can_see_all_leads_changes_what_that_user_sees(): void
    {
        $sales = $this->user('salesperson', 'Sam');
        $other = $this->user('salesperson', 'Sara');
        $this->project->salespeople()->attach($sales->id);

        $this->lead($sales, 'Own');
        $this->lead($other, 'Someone');

        $this->assertSame(1, Lead::visibleTo($sales->fresh())->count(), 'their own only');

        $this->actingAs($this->admin)
            ->put("/users/{$sales->id}", $this->editPayload($sales, [
                'permissions' => ['see_all_leads' => true, 'add_leads' => true, 'edit_leads' => true],
            ]))->assertSessionHasNoErrors();

        $this->assertSame(2, Lead::visibleTo($sales->fresh())->count(), 'now the whole pipeline');

        // and the page itself agrees, not just the scope
        $this->actingAs($sales->fresh())->get('/leads')
            ->assertInertia(fn ($page) => $page->where('leads.total', 2));
    }

    /** A granted permission has to survive the route, not only the policy. */
    public function test_a_telecaller_granted_add_leads_can_post_the_lead_form(): void
    {
        $tele = $this->user('telecaller', 'Tara');

        $this->actingAs($tele)->post('/leads', $this->leadPayload())->assertForbidden();

        $tele->update(['permissions' => ['add_leads' => true]]);

        $this->actingAs($tele->fresh())->post('/leads', $this->leadPayload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Lead::count());
    }

    /* ---------------- deactivating ---------------- */

    public function test_deactivating_someone_holding_open_leads_demands_a_handover(): void
    {
        $tele = $this->user('telecaller', 'Tara');
        $this->leadWithTask($tele);

        $this->actingAs($this->admin)
            ->put("/users/{$tele->id}", $this->editPayload($tele, ['is_active' => false]))
            ->assertSessionHasErrors('handover_to');

        $this->assertTrue($tele->fresh()->is_active, 'nothing moved on a rejected save');
    }

    public function test_deactivating_with_a_handover_moves_the_work_and_blocks_login(): void
    {
        $tele = $this->user('telecaller', 'Tara');
        $heir = $this->user('telecaller', 'Hema');
        $todo = $this->leadWithTask($tele);

        $this->actingAs($this->admin)
            ->put("/users/{$tele->id}", $this->editPayload($tele, [
                'is_active' => false, 'handover_to' => $heir->id,
            ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($tele->fresh()->is_active);
        $this->assertSame($heir->id, $todo->lead->fresh()->assigned_to);
        $this->assertSame('telecaller', $todo->lead->fresh()->assigned_role);
        $this->assertSame($heir->id, $todo->fresh()->assigned_to);
        $this->assertInvariantHolds();

        /*
         | Deactivation blocks login, which is LoginRequest's own `is_active`
         | credential and not something this feature added — this asserts it
         | still holds now that a screen can flip the column.
         |
         | post('/logout') first: the login route is behind `guest`, so posting
         | it while still signed in as the admin is redirected away and proves
         | nothing.
         */
        $this->post('/logout');

        $this->post('/login', ['login' => $tele->email, 'password' => 'password'])
            ->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_an_admin_cannot_deactivate_themselves(): void
    {
        // a second admin, so it is the self rule being tested and not the
        // last-admin one
        $second = $this->user('admin', 'Bob');

        $this->actingAs($this->admin)
            ->put("/users/{$this->admin->id}", $this->editPayload($this->admin, ['is_active' => false]))
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($this->admin->fresh()->is_active);

        $this->actingAs($this->admin)
            ->put("/users/{$second->id}", $this->editPayload($second, ['is_active' => false]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($second->fresh()->is_active);
    }

    /* ---------------- deleting ---------------- */

    public function test_deleting_with_reassignment_moves_everything_in_one_go(): void
    {
        $sales = $this->user('salesperson', 'Sam');
        $heir = $this->user('salesperson', 'Sara');
        $todo = $this->leadWithTask($sales);

        $this->actingAs($this->admin)
            ->delete("/users/{$sales->id}", ['handover_to' => $heir->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSoftDeleted('users', ['id' => $sales->id]);
        $this->assertNotNull(User::withTrashed()->find($sales->id), 'the row survives');

        $this->assertSame($heir->id, $todo->lead->fresh()->assigned_to);
        $this->assertSame($heir->id, $todo->fresh()->assigned_to);
        $this->assertSame('pending', $todo->fresh()->status, 'never cancelled');
        $this->assertInvariantHolds();
    }

    /**
     * Leave-unassigned, and the detail that makes it possible at all.
     *
     * `leads.assigned_to` is nullable and goes null, which is the point of the
     * option. `todos.assigned_to` is NOT NULL, so the pending task cannot
     * follow it — and cancelling it would leave an open lead with no task,
     * breaking the invariant. It goes to the admin instead.
     */
    public function test_deleting_with_leave_unassigned_keeps_the_invariant(): void
    {
        $sales = $this->user('salesperson', 'Sam');
        $todo = $this->leadWithTask($sales);

        $this->actingAs($this->admin)
            ->delete("/users/{$sales->id}", ['leave_unassigned' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSoftDeleted('users', ['id' => $sales->id]);
        $this->assertNull($todo->lead->fresh()->assigned_to, 'the lead is on nobody\'s list');
        $this->assertSame($this->admin->id, $todo->fresh()->assigned_to, 'the task went to the admin');
        $this->assertSame('pending', $todo->fresh()->status);
        $this->assertInvariantHolds();
    }

    public function test_deleting_without_choosing_either_option_is_refused(): void
    {
        $sales = $this->user('salesperson', 'Sam');
        $this->leadWithTask($sales);

        $this->actingAs($this->admin)
            ->delete("/users/{$sales->id}")
            ->assertSessionHasErrors('handover_to');

        $this->assertNotSoftDeleted('users', ['id' => $sales->id]);
    }

    public function test_work_can_only_be_handed_to_an_active_user_of_the_same_role(): void
    {
        $sales = $this->user('salesperson', 'Sam');
        $tele = $this->user('telecaller', 'Tara');
        $off = $this->user('salesperson', 'Sara');
        $off->update(['is_active' => false]);

        $this->leadWithTask($sales);

        $this->actingAs($this->admin)
            ->delete("/users/{$sales->id}", ['handover_to' => $tele->id])
            ->assertSessionHasErrors('handover_to');

        $this->actingAs($this->admin)
            ->delete("/users/{$sales->id}", ['handover_to' => $off->id])
            ->assertSessionHasErrors('handover_to');

        $this->assertNotSoftDeleted('users', ['id' => $sales->id]);
    }

    /**
     * Ann is the only admin, so she is the last active one — and because
     * `role:admin` only lets an active admin through, she is also the only
     * person who can reach this route. That is why the rule is checked before
     * the self rule: it is the only ordering in which it can ever fire.
     */
    public function test_the_last_active_admin_cannot_be_deleted(): void
    {
        $this->assertSame(1, User::active()->where('role', 'admin')->count());

        $this->actingAs($this->admin)->delete("/users/{$this->admin->id}")
            ->assertSessionHasErrors('user');

        $this->assertNotSoftDeleted('users', ['id' => $this->admin->id]);
        $this->assertSame(
            'This is the last active admin. Promote somebody else first.',
            session('errors')->first('user')
        );
    }

    /** With a second admin around, the self rule is what refuses it. */
    public function test_an_admin_cannot_delete_themselves(): void
    {
        $this->user('admin', 'Bob');

        $this->actingAs($this->admin)->delete("/users/{$this->admin->id}")
            ->assertSessionHasErrors('user');

        $this->assertNotSoftDeleted('users', ['id' => $this->admin->id]);
        $this->assertSame(
            'You cannot delete your own account.',
            session('errors')->first('user')
        );
    }

    /** The same two rules, on the deactivate path. */
    public function test_the_last_active_admin_cannot_be_deactivated(): void
    {
        $this->actingAs($this->admin)
            ->put("/users/{$this->admin->id}", $this->editPayload($this->admin, ['is_active' => false]))
            ->assertSessionHasErrors('is_active');

        $this->assertSame(
            'This is the last active admin. Promote somebody else first.',
            session('errors')->first('is_active')
        );
        $this->assertTrue($this->admin->fresh()->is_active);
    }

    /** History is the whole reason this is a soft delete. */
    public function test_a_deleted_users_completed_calls_survive(): void
    {
        $sales = $this->user('salesperson', 'Sam');
        $todo = $this->leadWithTask($sales);

        $todo->update([
            'status' => 'completed', 'completed_at' => now(),
            'completed_by' => $sales->id, 'outcome_stage' => 'connected',
        ]);
        $todo->lead->update(['stage' => 'lost', 'reason' => 'budget']);

        $this->actingAs($this->admin)->delete("/users/{$sales->id}")
            ->assertRedirect()->assertSessionHasNoErrors();

        $done = $todo->fresh();

        $this->assertSame('completed', $done->status);
        $this->assertSame($sales->id, $done->completed_by, 'who made the call is still recorded');
        $this->assertSame($sales->id, $done->assigned_to, 'a completed task is not reassigned');
    }

    /* ---------------- fixtures ---------------- */

    /** The invariant the whole application is built on. */
    private function assertInvariantHolds(): void
    {
        $this->assertSame(
            0,
            Lead::open()->doesntHave('pendingTodo')->count(),
            'every open lead must still hold exactly one pending task'
        );
    }

    /** An open lead owned by $owner, with the pending to-do that must travel with it. */
    private function leadWithTask(User $owner): Todo
    {
        $lead = $this->lead($owner, 'Meera');

        return Todo::create([
            'lead_id' => $lead->id,
            'assigned_to' => $owner->id,
            'created_by' => $this->admin->id,
            'scheduled_at' => now()->addDay(),
            'type' => 'call',
            'status' => 'pending',
        ]);
    }

    private function lead(User $owner, string $first): Lead
    {
        return Lead::create([
            'first_name' => $first,
            'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->project->id,
            'source' => 'walk_in',
            'stage' => 'connected',
            'assigned_to' => $owner->id,
            'assigned_role' => $owner->role,
            'created_by' => $this->admin->id,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'New',
            'last_name' => 'Person',
            'email' => 'new@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => 'telecaller',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'is_active' => true,
        ], $overrides);
    }

    /** The modal resubmits everything it is showing, so an edit does too. */
    private function editPayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'mobile_number' => $user->mobile_number,
            'role' => $user->role,
            'is_active' => $user->is_active,
        ], $overrides);
    }

    private function leadPayload(): array
    {
        return [
            'first_name' => 'Meera',
            'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->project->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'follow_up_type' => 'call',
            'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
        ];
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name' => $first, 'last_name' => 'User',
            'email' => strtolower($first).'@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
