<?php

namespace Plugin\RedirectManager\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\RedirectManager\Backend\Models\RedirectIssue;
use Plugin\RedirectManager\Backend\Repositories\RedirectIssueRepository;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__ . '/autoload.php';

/**
 * What Broken Links accepts as a write, and what it refuses.
 *
 * Rows here are written by anonymous visitors, so the screen is evidence rather than a record an
 * operator maintains: there is no create and no edit, and the only thing that moves is whether an
 * entry is still open. What matters is that the endpoints which cannot honour a write **say so**
 * — one of them used to answer 200 having changed nothing, which a caller cannot tell from
 * success.
 */
class IssueRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        RedirectIssue::query()->delete();

        $user = User::factory()->create([
            'status' => 'active',
            'email'  => 'rm-repo-' . bin2hex(random_bytes(4)) . '@example.com',
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        $this->actingAs($user);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        RedirectIssue::query()->delete();
        parent::tearDown();
    }

    private function repository(): RedirectIssueRepository
    {
        return app(RedirectIssueRepository::class);
    }

    private function issue(): RedirectIssue
    {
        return RedirectIssue::create([
            'type'         => RedirectIssue::TYPE_NOT_FOUND,
            'path'         => 'gone-' . bin2hex(random_bytes(3)),
            'hits'         => 5,
            'last_seen_at' => now(),
        ]);
    }

    /** @return HttpException the refusal, so a caller can assert its status */
    private function refusalFrom(callable $action): HttpException
    {
        try {
            $action();
            $this->fail('The write should have been refused.');
        } catch (HttpException $e) {
            return $e;
        }
    }

    #[Test]
    public function an_operator_cannot_invent_a_broken_link(): void
    {
        $refusal = $this->refusalFrom(
            fn () => $this->repository()->create(['type' => 'NOT_FOUND', 'path' => 'never-happened'])
        );

        $this->assertSame(422, $refusal->getStatusCode());
        $this->assertStringContainsString('cannot be added by hand', $refusal->getMessage());
        $this->assertSame(0, RedirectIssue::query()->count());
    }

    /**
     * The defect: the module is `readonly` and declares no `form`, so no validation rule is
     * derived and `validated()` is always empty — this wrote back the value the row already had
     * and answered 200. An endpoint that accepts a write and changes nothing is worse than one
     * that refuses, because the response looks identical to success.
     */
    #[Test]
    public function editing_an_entry_is_refused_and_names_the_action_that_works(): void
    {
        $issue = $this->issue();

        $refusal = $this->refusalFrom(
            fn () => $this->repository()->update($issue->id, ['resolved' => true, 'hits' => 1])
        );

        $this->assertSame(422, $refusal->getStatusCode());
        $this->assertStringContainsString('Dealt with', $refusal->getMessage());

        $issue->refresh();
        $this->assertFalse($issue->resolved);
        $this->assertSame(5, $issue->hits, 'The evidence is untouched.');
    }

    #[Test]
    public function the_action_the_refusal_names_does_work(): void
    {
        $issue = $this->issue();

        $this->repository()->savePageData('resolve', ['id' => $issue->id]);
        $this->assertTrue($issue->fresh()->resolved);

        $this->repository()->savePageData('reopen', ['id' => $issue->id]);
        $this->assertFalse($issue->fresh()->resolved);
    }

    #[Test]
    public function deleting_one_entry_still_works(): void
    {
        $issue = $this->issue();

        $this->assertTrue((bool) $this->repository()->delete($issue->id));
        $this->assertSame(0, RedirectIssue::query()->count());
    }

    #[Test]
    public function an_unknown_action_is_named_rather_than_silently_doing_nothing(): void
    {
        $this->assertSame(
            ['message' => 'Unknown action.'],
            $this->repository()->savePageData('not-a-thing', [])
        );
    }
}
