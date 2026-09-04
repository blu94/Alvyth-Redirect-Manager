<?php

namespace Plugin\RedirectManager\Tests;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two schema contracts a green PHP suite cannot see.
 *
 * Both defects here shipped past every backend test and were found only by opening the screen:
 * the engine read a key the schema had not written, and rendered nothing rather than failing.
 * That is the failure mode a schema-driven admin has instead of an exception, so the contracts
 * are asserted from the JSON directly.
 */
class AdminSchemaTest extends TestCase
{
    /** @return array<string,mixed> */
    private function schema(string $module, string $file): array
    {
        $path = dirname(__DIR__) . "/admin/modules/{$module}/{$file}";

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A view screen's actions must live in `sidebar.elements`.
     *
     * `ModuleWrapper` renders `sidebar.footer` for a **form** — it is Save Changes and Cancel —
     * and deliberately does not render it in view mode, where it mines the footer for one thing
     * only: an `action: "preview"` it lifts into the header. Everything else declared there is
     * simply never drawn.
     *
     * So the four actions on a broken link — including "Redirect to the best match", the whole
     * point of scoring suggestions — rendered nowhere at all, while the schema looked correct
     * and the repository dutifully computed `top_suggestion` for a button no one could reach.
     * Core's own `plugins/view.json` is the reference: buttons in `sidebar.elements`, no footer.
     */
    #[Test]
    public function the_broken_link_view_declares_its_actions_where_view_mode_draws_them(): void
    {
        $sidebar = $this->schema('redirect-issues', 'view.json')['sidebar'];

        $buttons = collect($sidebar['elements'])
            ->flatMap(fn ($el) => $el['elements'] ?? [$el])
            ->filter(fn ($el) => ($el['ui']['type'] ?? null) === 'button')
            ->values();

        $this->assertCount(4, $buttons, 'Every action on this screen has to be in sidebar.elements.');

        $this->assertSame(
            ['navigate', 'navigate', 'request', 'cancel'],
            $buttons->pluck('action')->all()
        );

        // The suggestion button is gated: with nothing close enough to offer, `top_suggestion`
        // is null and the button must not be there to be clicked.
        $this->assertSame(
            'top_suggestion,exists',
            $buttons->firstWhere('label', 'Redirect to the best match')['ui']['show_if'] ?? null
        );

        $this->assertArrayNotHasKey(
            'footer',
            $sidebar,
            'A footer on a view screen is never rendered, so an action left in one is invisible.'
        );
    }

    /**
     * A repeater's summary row must name the key it is showing.
     *
     * `BuilderRepeater` defaults its table to `[{ label: 'Item', key: 'title' }]`. The ignore
     * rules are `{ "pattern": … }`, so every row rendered a `-` while holding a real value —
     * a settings screen that reports having two rules it will not tell you the content of.
     * The count was right, which is what made it read as data rather than as a display bug.
     */
    #[Test]
    public function the_ignore_rules_repeater_shows_the_pattern_it_stores(): void
    {
        $repeater = collect($this->schema('redirect-rules', 'settings.json')['elements'])
            ->flatMap(fn ($row) => $row['elements'] ?? [])
            ->firstWhere('key', 'ignore_patterns');

        $this->assertNotNull($repeater);

        $rowKey = $repeater['elements'][0]['key'];

        $this->assertSame(
            [$rowKey],
            array_column($repeater['columns'] ?? [], 'key'),
            'The summary column must name the row field, or the table falls back to `title` and shows nothing.'
        );
    }

    /**
     * The storefront slot must never call its own class without checking it is there.
     *
     * A static assertion because the failure it guards cannot be reproduced from inside a test:
     * the class is loadable here by definition. What it pins is the shape of the template, and
     * that is worth pinning, because the guard looks removable and is not.
     *
     * The failure it prevents was live on this project's dev storefront: every 404 answering
     * **500** with `Class "Plugin\\RedirectManager\\Backend\\Support\\NotFoundSuggestions" not found`. Two different things decide whether the
     * slot renders and whether its code can load. The view namespace comes from
     * `active-{database}.json`, which core scopes per database; the class comes from an
     * autoloader reading a cached index under a key that is not scoped. A cache written by a
     * process pointed at another database leaves the template registered and the class absent —
     * and a plugin whose whole job is making missing pages work took every missing page down.
     *
     * `NotFoundSuggestions::for()` catches everything it can and none of it helps: the Error is
     * thrown resolving the class, before any of this package's code runs.
     */
    #[Test]
    public function the_storefront_slot_guards_the_class_it_calls(): void
    {
        $blade = file_get_contents(
            dirname(__DIR__) . '/frontend/blade/slots/not-found.blade.php'
        );

        // The prose above the code explains the guard and names the call, so searching the
        // whole file finds both in the wrong order. Only the template's code can be asserted on.
        $code = preg_replace('/\{\{--.*?--\}\}/s', '', $blade);

        $this->assertStringContainsString(
            'class_exists(',
            $code,
            'The slot calls into the package from a template. Without a guard, a moment when the '
            . 'view namespace is registered and the autoloader is not turns every 404 into a 500.'
        );

        // The call itself must sit on the true side of that guard, not merely somewhere in the
        // file — a guard that does not gate the call is decoration.
        $guardAt = strpos($code, 'class_exists(');
        $callAt  = strpos($code, 'NotFoundSuggestions::for(');

        $this->assertNotFalse($callAt, 'The slot still has to call the suggester.');
        $this->assertLessThan(
            $callAt,
            $guardAt,
            'The class check has to come before the call it protects.'
        );
    }
}
