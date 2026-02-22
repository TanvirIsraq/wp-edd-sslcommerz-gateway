# Documentation Update Policy

## Non-Negotiable Rule
When code changes, corresponding docs in `.agent` must be updated in the same change set.

## Required Updates by Change Type
- Gateway flow logic changed:
  Update `implementation-guide.md`, `walkthrough.md`, and `glossary.md` if terms changed.
- New hook, endpoint, or callback added:
  Update `implementation-guide.md`, `communication.md`, and `tasks.md`.
- New data keys or payment meta fields added:
  Update `glossary.md`.
- Task scope changed:
  Update `tasks.md` and `PLAN.md`.
- Agent ownership changed:
  Update `roles.md`.

## Definition of Done (Docs)
- Behavior in docs matches current code paths.
- File/function names are accurate.
- Status in `tasks.md` and `PLAN.md` reflects reality.
- New terms are added to `glossary.md`.

## PR/Commit Checklist
- [ ] Updated `.agent` docs for impacted areas.
- [ ] Removed stale statements from docs.
- [ ] Verified key flow in `walkthrough.md` still runs as documented.
