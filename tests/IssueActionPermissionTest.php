<?php

namespace Plugin\RedirectManager\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\RedirectManager\Backend\Models\RedirectIssue;
use Plugin\RedirectManager\Backend\Models\RedirectSetting;
use Plugin\RedirectManager\Backend\Repositories\RedirectIssueRepository;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__ . '/autoload.php';

/**
 * Who may clear the evidence.
 *
 * A plugin registers no routes, so every button on Broken Links arrives as
 * `POST .../page/{slug}` — and a POST means `create`. **Prune history** deletes recorded 404s
 * in bulk, and on that mapping alone it was admitted to anyone holding
 * `redirect_manager.create` while being refused to a role granted `redirect_manager.delete`
 * and nothing else.
 *
 * `module.json` now declares each page's real verb, and the repository checks the same verb
 * itself — so the package still fails closed on a core that predates the declaration.
 */
class IssueActionPermissionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        RedirectIssue::query()->delete();
        RedirectSetting::forget();

        // Spatie's cached map cannot see rows created inside the test transaction, and a
        // stale map answers a spurious refusal.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        RedirectIssue::query()->delete();
        RedirectSetting::forget();

        parent::tearDown();
    }

    /** @param array<int,string> $permissions */
    private function actAs(array $permissions = [], ?string $role = null): User
    {
        $user = User::factory()->create([
            'status' => 'active',
            'email'  => 'rm-perm-' . bin2hex(random_bytes(4)) . '@example.com',
        ]);

        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        if ($role !== null) {
            $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        }

        $this->actingAs($user);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function repository(): RedirectIssueRepository
    {
        return app(RedirectIssueRepository::class);
    }

    private function issue(bool $resolved = false): RedirectIssue
    {
        return RedirectIssue::create([
            'type'         => RedirectIssue::TYPE_NOT_FOUND,
            'path'         => 'gone-' . bin2hex(random_bytes(3)),
            'hits'         => 3,
            'resolved'     => $resolved,
            'last_seen_at' => now()->subYears(2),
        ]);
    }

    private function assertRefused(callable $action, int $status = 403): void
    {
        try {
            $action();
            $this->fail('The action should have been refused.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    #[Test]
    public function create_alone_no_longer_prunes_recorded_history(): void
    {
        $this->actAs(['redirect_manager.view', 'redirect_manager.create']);

        RedirectSetting::current()->update(['retention_days' => 1]);
        RedirectSetting::forget();

        $old = $this->issue(resolved: true);

        $this->assertRefused(fn () => $this->repository()->savePageData('prune', []));

        $this->assertDatabaseHas('redirect_manager_issues', ['id' => $old->id]);
    }

    #[Test]
    public function delete_prunes_it(): void
    {
        $this->actAs(['redirect_manager.delete']);

        RedirectSetting::current()->update(['retention_days' => 1]);
        RedirectSetting::forget();

        $old = $this->issue(resolved: true);

        $result = $this->repository()->savePageData('prune', []);

        $this->assertStringContainsString('Removed 1', $result['message']);
        $this->assertDatabaseMissing('redirect_manager_issues', ['id' => $old->id]);
    }

    #[Test]
    public function marking_an_entry_dealt_with_is_an_update_not_a_create(): void
    {
        $this->actAs(['redirect_manager.view', 'redirect_manager.create']);

        $issue = $this->issue();

        $this->assertRefused(
            fn () => $this->repository()->savePageData('resolve', ['id' => $issue->id])
        );

        $this->assertFalse($issue->fresh()->resolved);

        $this->actAs(['redirect_manager.update']);

        $this->repository()->savePageData('resolve', ['id' => $issue->id]);

        $this->assertTrue($issue->fresh()->resolved);
    }

    #[Test]
    public function reopening_is_an_update_too(): void
    {
        $this->actAs(['redirect_manager.create']);

        $issue = $this->issue(resolved: true);

        $this->assertRefused(
            fn () => $this->repository()->savePageData('reopen', ['id' => $issue->id])
        );

        $this->assertTrue($issue->fresh()->resolved);
    }

    /** The standing escape hatch, exactly as `GateModuleResource` treats it. */
    #[Test]
    public function a_super_admin_needs_no_named_permission(): void
    {
        $this->actAs(role: 'super_admin');

        $issue = $this->issue();

        $this->repository()->savePageData('resolve', ['id' => $issue->id]);

        $this->assertTrue($issue->fresh()->resolved);
    }
}
