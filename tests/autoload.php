<?php

/*
|--------------------------------------------------------------------------
| Make this package's classes loadable from its own tests
|--------------------------------------------------------------------------
| Alvyth resolves `Plugin\RedirectManager\…` through a runtime autoloader in
| `PluginServiceProvider`, keyed on the installed set read from the `plugins`
| table — one of the three independent gates that stop a disabled plugin from
| running. It is a runtime autoloader rather than a composer PSR-4 entry
| because the set of paths is not known until the database says which plugins
| are installed.
|
| The suite empties that table before the first test (`tests/bootstrap.php`):
| enabling a plugin runs DDL, MySQL commits implicitly, and the row is
| committed along with the `CREATE TABLE`, so it outlives the rollback that
| was meant to remove it and the *next* run inherits it. Correct — but it
| means no amount of reinstalling puts a row in front of these tests. By the
| time one of them asks for `RedirectRule` there is nothing to resolve it,
| and all 21 error with `Class … not found`.
|
| A package's own tests need two things: its classes on the autoloader and
| its tables in the schema. Neither is a registry entry, so the dependency is
| removed here rather than worked around outside. The tables still come from
| `plugin:import --enable` against `alvyth_test`, which survives the run — only
| the row is cleared.
|
| Mirrors the provider's mapping exactly, including lowercasing the first
| segment (`Backend` → `backend`) to match the folder casing on disk.
| `require_once` from each test file makes this run once per process;
| `spl_autoload_register` is global and stacking duplicate closures would make
| every unresolved class walk them all.
*/

spl_autoload_register(static function (string $class): void {
    $prefix = 'Plugin\\RedirectManager\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $segments = explode('\\', substr($class, strlen($prefix)));

    $segments[0] = strtolower($segments[0]);

    $file = dirname(__DIR__) . '/' . implode('/', $segments) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
