# AGENTS.md

Instructions for working **on phporbit itself** live in
**[CLAUDE.md](CLAUDE.md)**. Read that file before making changes — it explains
the process-model constraint that every design decision in this repository
follows from, and the invariants that are not negotiable.

CLAUDE.md is the canonical file here, rather than this one, for a practical
reason: Claude Code loads it automatically, so keeping the substance there means
the rules are in context before the first edit rather than after a file read.
A second copy would drift from it within a week, and the two would disagree
exactly when it mattered.

**The direction is reversed in applications built with phporbit.** A project
scaffolded by `orbit new` gets an `AGENTS.md` carrying the rules, with
`CLAUDE.md` and `.github/copilot-instructions.md` pointing at it — the broadest
convention wins there, because those projects are worked on by every kind of
assistant. The stubs live in `stubs/skeleton/`.
