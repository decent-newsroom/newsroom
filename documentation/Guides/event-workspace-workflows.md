# Event workspace agent workflows

The optional Copilot CLI extension coordinates one bounded event-workspace
change at a time. It uses the project skills and custom agent profiles; it does
not implement the [workspace foundation](../Reader/event-workspace-iteration-plan.md)
or start the next migration automatically.

## Workflows

| Name | One target per run | Procedure |
|---|---|---|
| `event-workspace-migrate-surface` | An existing page, embed, or inventory, such as Reading Nook | [Migrate an event surface](../../.agents/skills/migrate-event-surface/SKILL.md) |
| `event-workspace-add-viewer` | One viewer or child/member display mode, such as follow-list profile cards | [Add an event viewer](../../.agents/skills/add-event-viewer/SKILL.md) |

Each takes an object with a required `target` string and optional `notes`
string. For example, ask Copilot to run `event-workspace-migrate-surface` with
`{"target":"Reading Nook"}`. Review the previous result before starting a
separate run for the next target. Do not run overlapping changes concurrently
in the same worktree.

## Activation

The entrypoint is
`.github/extensions/event-workspace/extension.mjs`; the CLI discovers
repository extensions in `.github/extensions/` when extension support is
enabled and the repository is trusted. This JavaScript is agent tooling, not a
browser asset; its location is required by extension discovery. The CLI
provides `@github/copilot-sdk/extension`, so the project does not need an npm
dependency for it.

Dynamic Workflows are an experimental Copilot feature. In Copilot CLI, start
`copilot --experimental` or use `/experimental on`, trust the repository, then
start a new session or use `/clear` to reload extensions and clear the current
conversation. Use `/extensions manage` to inspect the extension's status, and
ask Copilot to list registered workflows to confirm both names appear.
Extensions execute with your local privileges; load only extension code you
trust. An SDK host must request extensions and repository configuration
discovery when creating the session
(`requestExtensions: true` and `enableConfigDiscovery: true` in the Node SDK).
A session that reports "extensions are not configured" cannot register the
extension by changing repository files alone. Availability, trust, and
execution eligibility depend on the host; see
[Using dynamic workflows](https://docs.github.com/en/copilot/how-tos/use-copilot-agents/use-dynamic-workflows),
the [Copilot SDK extension guide](https://github.com/github/copilot-sdk/blob/main/nodejs/docs/extensions.md),
and [workflow guide](https://github.com/github/copilot-sdk/blob/main/nodejs/docs/workflows.md).

## Gates and results

1. A read-only preflight verifies a single target, the shared event contract,
   generic preview, reference handling, source-adapter interface, access rules,
   and safe handling of existing worktree changes. Missing foundation or
   ambiguous scope blocks the run before implementation.
2. The implementer follows the appropriate skill, preserves existing behavior,
   adds focused coverage, and reports the changed files and Docker checks.
3. The reviewer independently checks the changes and runs relevant Docker
   validation. Actionable findings trigger at most one fix and one re-review
   for that same target; the workflow never advances to another target.

The result's `status` is `verified`, `blocked`, or `changes_requested`.
`verified` requires a completed implementation, a finding-free review, and
reported passing Docker tests from both agents. When reported files include
application JavaScript, TypeScript, or CSS, both agents must also report a
passing `asset-map:compile`. Failed, missing, or unrun checks, an absent agent
report, and unresolved review findings are not passes.
Review the evidence and diff before approving the next target. A workflow run
can itself finish successfully while its result is `blocked` or
`changes_requested`; check the result, not just the run status or CLI exit code.

The workflow has no guessed credit, time, or subagent ceilings. A
model-initiated run follows the session's approval settings and can use limits
explicitly chosen for that run; direct CLI or SDK invocation may not prompt for
approval. The agents' judgments and reported evidence are not a substitute for
human review. The reviewer has command-execution access to run checks; its
read-only role is an instruction, not a filesystem sandbox. An interrupted
workflow may replay completed agent reports on resume: do not modify relevant
files while a run is active or paused; if they change, cancel it and start a
fresh run instead of relying on stale reports.

The raw-event presentation, ordered references, and a standalone preview are a
partial foundation. They do not yet provide the shared source-adapter interface
or an authorized target resolver and result contract. Until the missing
prerequisites exist, preflight should block migrations and viewers. Run the
Symfony application checks in the PHP container as documented in
[AGENTS.md](../../AGENTS.md).

## Extension checks

The orchestration tests use Node's built-in test runner and a stub workflow
context; they do not start agents or require the Copilot SDK:

```powershell
docker run --rm --network none --mount "type=bind,source=${PWD},target=/workspace,readonly" --workdir /workspace node:22-alpine node --test .github/extensions/event-workspace/workflows.test.mjs
```

These tests verify the control flow, not live registration. Confirm extension
discovery and a real run in an extension-enabled session separately.
