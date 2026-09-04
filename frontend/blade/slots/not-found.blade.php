{{--
    Suggestions on the shop's 404 page.

    Rendered through `<x-plugin-slot name="not-found" />`, which the active theme has to call —
    core defines the hook but every pixel of the storefront lives in a theme template, so there
    is no position core can occupy on its own. A theme that does not call it renders nothing,
    which is why the setting behind this is off by default.

    Opting in is shipping this file. There is nothing to register.

    **The class is checked before it is called, and that guard is load-bearing.** This file used
    to say that a disabled or blocked plugin is absent from the storefront manifest, so its view
    namespace is never registered and the region renders empty — "the 404 page still serves
    either way". That was wrong, and it was wrong in the worst possible direction: a plugin whose
    job is to make missing pages work took every missing page down.

    Two different things decide whether this slot renders and whether its code can load, and they
    can disagree. The view namespace comes from `storage/app/plugins/active-{database}.json`,
    which `PluginRegistry::manifestPath()` scopes per database. The class comes from the `Plugin\`
    autoloader, which reads `PluginRegistry::namespaces()` out of a cached index. When those two
    disagree — a cache written by a process pointed at another database, a cache cleared and
    rebuilt at the wrong moment, a half-finished lifecycle transition — the template renders and
    the class does not exist. Observed on this project's dev storefront: every 404 answering 500
    with `Class "Plugin\RedirectManager\Backend\Support\NotFoundSuggestions" not found`.

    `NotFoundSuggestions::for()` catches everything it can, and none of it helps here: the Error
    is thrown resolving the class, before any of this package's code runs. A template is the only
    place the check can go, because a class cannot guard its own absence.

    `$slotData` carries whatever the calling page knew. `path` is the one thing needed here; a
    theme that passes nothing still renders safely, it just has nothing to suggest.

    The settings read, the scoring and the degrade-to-nothing on failure all live in
    `Backend\Support\NotFoundSuggestions` — presentation only below.
--}}
@php
    $suggestions = class_exists(\Plugin\RedirectManager\Backend\Support\NotFoundSuggestions::class)
        ? \Plugin\RedirectManager\Backend\Support\NotFoundSuggestions::for($slotData['path'] ?? null)
        : [];
@endphp

@if (! empty($suggestions))
    <div class="redirect-manager-suggestions">
        <p>Were you looking for one of these?</p>

        <ul>
            @foreach ($suggestions as $suggestion)
                <li>
                    <a href="{{ url('/' . $suggestion['path']) }}">{{ $suggestion['label'] }}</a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
