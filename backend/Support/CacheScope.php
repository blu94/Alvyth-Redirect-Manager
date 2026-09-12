<?php

namespace Plugin\RedirectManager\Backend\Support;

/**
 * Which database a cached value belongs to.
 *
 * **A flat cache key is shared by every schema pointed at one cache store**, and everything this
 * plugin caches is read out of one database: the rule set, the candidate paths scored against a
 * dead URL, the count behind the recording ceiling. None of it means anything in another schema.
 *
 * This repository's own dev container is the case that proves it rather than a hypothetical:
 * `alvyth` and `alvyth_test` share a Redis, so a suite run could hand the shop another database's
 * rules and the shop could hand the suite its own. `MULTISITE-SPEC.md` §13 names exactly this
 * shape — `active_theme_config` under a flat key — as *"the negative precedent … the first thing
 * multi-site falsifies"*, while §4 makes redirect identity `(site_id, locale, slug)`.
 *
 * The remedy is core's own, not a new idea: `Theme::activeConfigPath()` builds a filename from
 * the connection's database name for the same reason, and constrains it for the same one — it is
 * only ever a database identifier, but it is being put somewhere structured, so it is bounded
 * rather than trusted.
 *
 * A class of its own because three unrelated services need the same answer, and a copy in each
 * is three chances for them to disagree about what a cached value belongs to.
 */
class CacheScope
{
    /** The current connection's database, safe to put in a cache key. */
    public static function name(): string
    {
        $connection = (string) config('database.default');
        $database   = (string) config("database.connections.{$connection}.database");

        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $database) ?: 'default';
    }

    /** One key, scoped to this database. */
    public static function key(string $key): string
    {
        return $key . ':' . self::name();
    }
}
