# CLAUDE.md → see `src/CLAUDE.md`

This is a Laravel app. The project lives in **`src/`**, and that's where Claude Code
and Laravel Boost operate. The authoritative, maintained guidelines — Boost's
framework rules **plus** this project's architecture/conventions — live in:

    src/CLAUDE.md   (mirrored to src/AGENTS.md)

**Launch `claude` from `src/`** so Boost's MCP server and those guidelines load.
For live structure use Boost (`php artisan route:list`, `database-schema`) rather
than a hardcoded list here.

The project root holds Docker / Makefile / env only — see `README.md` and
`ARCHITECTURE.md` for infra.
