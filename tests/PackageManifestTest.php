<?php

namespace Plugin\RedirectManager\Tests;

use App\Services\Plugin\PluginManifest;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one claim the manifest makes that this package cannot survive being wrong about.
 */
class PackageManifestTest extends TestCase
{
    private function manifest(): PluginManifest
    {
        $path = dirname(__DIR__) . '/plugin.json';

        $this->assertFileExists($path);

        return PluginManifest::fromArray(
            json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * A root rule needs a core that asks about the root.
     *
     * An empty `from_path` plus a query is how an old permalink like `/?p=123` is caught — one
     * naming the page in the query rather than the path — and it only works on a build whose
     * `ThemeController` dispatches `PathNotResolved` for `/` when the request carries a query
     * string. That build is 1.3.0.
     *
     * While the constraint said `>=1.2.0` the package installed happily onto a build with no
     * root dispatch, where the rule saves, appears in the list, and silently never fires —
     * which is precisely the failure this package exists to prevent, reproduced in its own
     * manifest. Loosening the floor again would bring it back with no other symptom.
     */
    #[Test]
    public function the_package_refuses_a_core_that_cannot_dispatch_a_root_miss(): void
    {
        $manifest = $this->manifest();

        $this->assertFalse($manifest->satisfiedBy('1.2.0'), 'A build with no root dispatch must be refused.');
        $this->assertFalse($manifest->satisfiedBy('1.2.9'));

        // The upper bound is the other half of the promise: a 2.x core is a different
        // contract, and installing into one unread is how a package breaks quietly.
        $this->assertFalse($manifest->satisfiedBy('2.0.0'));
    }

    /**
     * Four things this package does have a half that lives in Ovynt itself, and none of them
     * announce their absence:
     *
     * - a recorded 404 seeding the rule form is only safe because core encodes a substituted
     *   value for the position it lands in;
     * - a redirect keeps the visitor's locale only because core carries the stripped segment
     *   on `PathNotResolved`;
     * - **Prune history** is gated on `delete` rather than `create` only because core reads a
     *   page's declared verb;
     * - a refused rule answers as a refusal rather than a 500 only because core lets a client
     *   fault out at its own status.
     *
     * On an older core each one silently reverts to the defect it replaced — the package would
     * install, the screens would work, and the open-redirect path would be back with nothing to
     * say so. That is the same shape as the root-dispatch floor above, and it gets the same
     * answer: refuse at install rather than run wrong.
     */
    #[Test]
    public function the_package_refuses_a_core_without_the_halves_these_fixes_need(): void
    {
        $manifest = $this->manifest();

        $this->assertFalse($manifest->satisfiedBy('1.3.0'), 'The root dispatch alone is no longer enough.');
        $this->assertFalse($manifest->satisfiedBy('1.4.0'));
        $this->assertTrue($manifest->satisfiedBy('1.4.1'));
        $this->assertTrue($manifest->satisfiedBy('1.5.0'));
    }

    /**
     * The floor has to name a version that exists, or it is a promise about nothing.
     *
     * **This catches the floor being declared AHEAD of the core, and only that.** A manifest
     * that names a release nobody has cut yet refuses to install everywhere, including here —
     * which is a real mistake and an easy one to make while a core change is still in a working
     * tree rather than a commit.
     *
     * It does **not** catch the opposite, and an earlier version of this docblock claimed it
     * did. A floor of `>=1.3.0` against a core of 1.4.1 satisfies, so the understated
     * constraint this package actually shipped with would have passed here without a murmur.
     * The test that catches understatement is the one above, and it catches it because it names
     * the release explicitly: `assertFalse($manifest->satisfiedBy('1.4.0'))`. A lower bound can
     * only be pinned by stating it.
     *
     * Two assertions, opposite mistakes, both worth keeping. (Caught by ovynt-c3, who read this
     * rationale before copying it into another package rather than after.)
     */
    #[Test]
    public function the_core_this_runs_on_satisfies_the_floor_the_manifest_declares(): void
    {
        $this->assertTrue(
            $this->manifest()->satisfiedBy((string) config('ovynt.version')),
            'The declared floor is ahead of the core in this tree, so the package would refuse to install on it.'
        );
    }
}
