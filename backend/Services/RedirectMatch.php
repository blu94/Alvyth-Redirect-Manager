<?php

namespace Plugin\RedirectManager\Backend\Services;

use Plugin\RedirectManager\Backend\Models\RedirectRule;

/**
 * What the matcher concluded about one path.
 *
 * A value object rather than a bare rule, because resolving a path can end three ways and the
 * caller has to tell them apart: a destination to send the visitor to, a fault in the rules
 * worth recording, or nothing at all. Returning only a rule would collapse the second into
 * the third, and a redirect loop would look exactly like a path nobody wrote a rule for.
 *
 * **The matcher does not write anything.** It reports `$problem` and the caller records it.
 * Keeping the read path free of writes is what lets the matcher be exercised without a
 * transaction, and it keeps the decision about whether to log — an operator setting — in one
 * place rather than two.
 */
final class RedirectMatch
{
    /**
     * `$destination` is the same answer in **stored** form — a bare path for somewhere on this
     * site, an absolute URL for somewhere else. `$target` is that made followable. Both are
     * here because the caller has one thing the matcher does not: the locale the request
     * arrived under, which core strips before asking and which has to go back on the front of
     * an internal destination. Telling the two apart by looking at `$target` is not possible —
     * `url()` makes every internal path absolute — which is the same trap `resolvedTo()` and
     * `target()` are kept separate for.
     *
     * `$usedRuleIds` is every rule the walk went through, first to last. The first one is the
     * one that matched the request and the one whose code is answered with — but a chain is
     * *served* by all of them, and counting only the first left every rule in the middle
     * reporting zero hits on a screen that offers to tidy away rules nobody uses.
     *
     * @param  RedirectRule|null  $rule         the rule that matched the request; the one to count
     * @param  string|null        $target       where to send the visitor, or null if nowhere
     * @param  int                $code         HTTP status to answer with
     * @param  array<int,string>  $walked       the paths passed through, in order
     * @param  string|null        $problem      a RedirectIssue::TYPE_* the caller should record
     * @param  string|null        $destination  the same answer as stored, before `url()`
     * @param  array<int,int>     $usedRuleIds  every rule the walk went through, in order
     */
    public function __construct(
        public readonly ?RedirectRule $rule = null,
        public readonly ?string $target = null,
        public readonly int $code = 301,
        public readonly array $walked = [],
        public readonly ?string $problem = null,
        public readonly ?string $destination = null,
        public readonly array $usedRuleIds = [],
    ) {
    }

    /** Whether the destination points at another host, where this site's locale means nothing. */
    public function isOffsite(): bool
    {
        return $this->destination !== null && RedirectRule::isAbsoluteUrl($this->destination);
    }

    /** Whether there is somewhere to send the visitor. */
    public function hasTarget(): bool
    {
        return $this->rule !== null && $this->target !== null && $this->target !== '';
    }

    /** Nothing matched and nothing was wrong — the path is simply a miss. */
    public static function none(): self
    {
        return new self();
    }
}
