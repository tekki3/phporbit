The instructions for this project live in [`AGENTS.md`](../AGENTS.md) at the
repository root. Read that file before suggesting changes.

It is kept as the single source so that Copilot, Claude Code, Cursor and any
other assistant work from the same rules — a second copy would drift from it
within a week, and the two would disagree exactly when it mattered.

In short: this is a **phporbit** application, not Laravel. Never use
`$_SESSION`, superglobals, `STDERR`, static mutable state, or interpolated SQL;
prefer `{{ }}` over `{!! !!}` in templates; one class per route implementing
`PhpOrbit\Routing\Handler`. `AGENTS.md` explains why each of those matters.
