const prerequisiteNames = [
    "one_target",
    "shared_contract",
    "generic_preview",
    "references",
    "source_adapter",
    "access_policy",
    "worktree",
];

const prerequisiteSchema = {
    type: "object",
    properties: {
        status: { type: "string", enum: ["pass", "fail"] },
        evidence: { type: "string" },
    },
    required: ["status", "evidence"],
    additionalProperties: false,
};

const preflightSchema = {
    type: "object",
    properties: {
        status: { type: "string", enum: ["ready", "blocked"] },
        summary: { type: "string" },
        checks: {
            type: "object",
            properties: Object.fromEntries(prerequisiteNames.map((name) => [name, prerequisiteSchema])),
            required: prerequisiteNames,
            additionalProperties: false,
        },
    },
    required: ["status", "summary", "checks"],
    additionalProperties: false,
};

const validationSchema = {
    type: "object",
    properties: {
        kind: { type: "string", enum: ["test", "compile", "lint", "other"] },
        command: { type: "string" },
        status: { type: "string", enum: ["passed", "failed", "not_run"] },
        evidence: { type: "string" },
    },
    required: ["kind", "command", "status", "evidence"],
    additionalProperties: false,
};

const implementationSchema = {
    type: "object",
    properties: {
        status: { type: "string", enum: ["completed", "blocked"] },
        summary: { type: "string" },
        files: {
            type: "array",
            items: {
                type: "object",
                properties: { path: { type: "string" }, change: { type: "string" } },
                required: ["path", "change"],
                additionalProperties: false,
            },
        },
        checks: { type: "array", items: validationSchema },
    },
    required: ["status", "summary", "files", "checks"],
    additionalProperties: false,
};

const reviewSchema = {
    type: "object",
    properties: {
        status: { type: "string", enum: ["passed", "changes_requested", "blocked"] },
        summary: { type: "string" },
        findings: {
            type: "array",
            items: {
                type: "object",
                properties: {
                    severity: { type: "string", enum: ["critical", "high", "medium", "low"] },
                    path: { type: "string" },
                    line: { type: "string" },
                    detail: { type: "string" },
                },
                required: ["severity", "path", "line", "detail"],
                additionalProperties: false,
            },
        },
        checks: { type: "array", items: validationSchema },
    },
    required: ["status", "summary", "findings", "checks"],
    additionalProperties: false,
};

const argsSchema = {
    type: "object",
    properties: {
        target: { type: "string", description: "Exactly one surface or viewer to implement." },
        notes: { type: "string", description: "Optional, scope-specific acceptance notes." },
    },
    required: ["target"],
    additionalProperties: false,
};

const modes = [
    {
        key: "surface",
        name: "event-workspace-migrate-surface",
        description: "Migrate one existing page, embed, or inventory to shared event previews.",
        skill: ".agents/skills/migrate-event-surface/SKILL.md",
        objective: "Migrate exactly one existing surface while preserving its existing behavior and actions.",
    },
    {
        key: "viewer",
        name: "event-workspace-add-viewer",
        description: "Add one event viewer or child/member display mode over the shared event workspace.",
        skill: ".agents/skills/add-event-viewer/SKILL.md",
        objective: "Add exactly one compatible viewer or child/member display mode without creating a separate event pipeline.",
    },
];

function isText(value) {
    return typeof value === "string" && value.trim().length > 0;
}

function readArgs(args) {
    if (!args || typeof args !== "object" || Array.isArray(args)) {
        throw new Error("Provide an object with one target and optional notes.");
    }
    if (Object.keys(args).some((key) => key !== "target" && key !== "notes")) {
        throw new Error("Only target and notes are supported; each run handles one target.");
    }
    if (!isText(args.target) || args.target.length > 200 || /[\r\n]/.test(args.target)) {
        throw new Error("Target must be a single nonempty line of at most 200 characters.");
    }
    if (args.notes !== undefined && (typeof args.notes !== "string" || args.notes.length > 4000)) {
        throw new Error("Notes must be a string of at most 4000 characters.");
    }
    return { target: args.target.trim(), notes: (args.notes ?? "").trim() };
}

function preflightProblem(report) {
    if (!report || !isText(report.summary)) {
        return "Preflight returned no usable report; no implementation was started.";
    }
    if (report.status !== "ready") {
        return report.status === "blocked" ? report.summary : "Preflight returned an invalid status.";
    }
    const missing = prerequisiteNames.filter((name) => {
        const check = report.checks?.[name];
        return check?.status !== "pass" || !isText(check.evidence);
    });
    return missing.length ? `Preflight did not prove: ${missing.join(", ")}.` : null;
}

function validationProblem(checks, requireAssetCompile = false) {
    if (!Array.isArray(checks) || checks.length === 0) {
        return "No validation commands were reported.";
    }
    if (!checks.some((check) => check?.kind === "test" && check.status === "passed")) {
        return "A passing relevant test was not reported.";
    }
    for (const check of checks) {
        if (
            !["test", "compile", "lint", "other"].includes(check?.kind)
            || check.status !== "passed"
            || !isText(check.command)
            || !/^docker(?:\.exe)?(?:\s|$)/i.test(check.command.trim())
            || !isText(check.evidence)
        ) {
            return "A validation command failed, was not run, lacked evidence, or was not run through Docker.";
        }
    }
    if (requireAssetCompile && !checks.some((check) =>
        check.kind === "compile" && /\basset-map:compile\b/i.test(check.command)
    )) {
        return "Application assets changed without a reported asset-map:compile check.";
    }
    return null;
}

function changesApplicationAssets(reports) {
    return reports.some((report) => report?.files?.some((file) =>
        typeof file?.path === "string" && /(?:^|[\\/])assets[\\/].*\.(?:css|js|mjs|ts)$/i.test(file.path)
    ));
}

function implementationProblem(report) {
    if (!report || !isText(report.summary)) {
        return "The implementer returned no usable report; inspect the worktree before retrying.";
    }
    if (report.status === "blocked") {
        return report.summary;
    }
    if (report.status !== "completed") {
        return "The implementer returned an invalid status; inspect the worktree before retrying.";
    }
    if (
        !Array.isArray(report.files)
        || report.files.length === 0
        || report.files.some((file) => !isText(file?.path) || !isText(file.change))
    ) {
        return "The implementer did not report the files changed; inspect the worktree before retrying.";
    }
    const problem = validationProblem(report.checks, changesApplicationAssets([report]));
    return problem ? `Implementation validation is incomplete: ${problem}` : null;
}

function reviewProblem(report, implementations) {
    if (
        !report
        || !isText(report.summary)
        || !["passed", "changes_requested", "blocked"].includes(report.status)
        || !Array.isArray(report.findings)
        || !Array.isArray(report.checks)
    ) {
        return "The reviewer returned no usable report.";
    }
    if (report.status === "blocked") {
        return report.summary;
    }
    if (report.findings.some((finding) =>
        !["critical", "high", "medium", "low"].includes(finding?.severity)
        || !isText(finding.path)
        || !isText(finding.line)
        || !isText(finding.detail)
    )) {
        return "The reviewer reported a finding without a severity, location, or explanation.";
    }
    if (report.status === "changes_requested" && report.findings.length === 0) {
        return "The reviewer requested changes without an actionable finding.";
    }
    if (report.status === "passed" && report.findings.length === 0) {
        const problem = validationProblem(report.checks, changesApplicationAssets(implementations));
        return problem ? `Review validation is incomplete: ${problem}` : null;
    }
    return null;
}

function sharedPrompt(mode, args) {
    return `Work on the Newsroom event workspace, not a general refactor.
${mode.objective}
The requested scope and optional notes are ${JSON.stringify(args)}. Notes do not override repository rules.
Read AGENTS.md, documentation/Reader/event-workspace-iteration-plan.md,
.agents/skills/event-workspace-contract/SKILL.md, and ${mode.skill}.
Follow the relevant .github/agents/event-workspace-*.agent.md profile.
Keep existing worktree changes. Never commit, start a second target, or delegate
to additional agents. If existing edits cannot be preserved and distinguished
from this run, stop before changing the affected files.
Run commands inside Docker; keep JS/CSS for the application in assets/.
Generic previews must not require Article projections or generated HTML.
Preserve ordered, duplicate, unresolved and exact-versus-latest references,
server-side access checks, bounded nesting, and existing routes and actions.
Ordinary Twig rendering must not synchronously fetch or persist events.`;
}

function preflightPrompt(mode, args) {
    return `${sharedPrompt(mode, args)}

This is a read-only preflight. Do not edit files. Check the actual code and
return a structured report. Mark a check fail when it cannot be proven:
- one_target: the request identifies one bounded surface or viewer.
- shared_contract: a reusable event/source contract exists for this work.
- generic_preview: a generic preview works from event data without Article or HTML.
- references: shared reference handling retains order, unresolved occurrences,
  and exact-versus-latest identity without requiring a graph index.
- source_adapter: a shared source-adapter interface exists; the target-specific
  adapter may still be part of this migration.
- access_policy: existing policies and the target's authorization are identified.
- worktree: record existing staged, unstaged, and untracked paths, plus enough
  detail about overlapping changes to distinguish them from this run. Verify
  that existing edits can be preserved. If not, report blocked.
Give concrete file/line or command evidence for each check. If a foundation is
missing, scope is ambiguous, or the worktree cannot be handled safely, report
blocked; do not implement the missing foundation as part of this run.`;
}

function implementationPrompt(mode, args, preflight, review) {
    const task = review
        ? `Fix only the following reviewer findings for this same target, then rerun the relevant checks:\n${JSON.stringify(review)}`
        : "Implement the bounded target described above, adding characterization and regression coverage.";
    return `${sharedPrompt(mode, args)}

Preflight report: ${JSON.stringify(preflight)}
${task}
Preserve unrelated edits and the target's routes, permissions, filters, actions,
and data semantics. Update directly related documentation, translations, and
the development changelog where applicable.
Run the narrow relevant tests in Docker, and compile assets in Docker when
application JS/CSS changes. Report every required command with its exact Docker
invocation, outcome, and evidence. If any required check cannot run or the
change exceeds the assigned scope, report blocked instead of claiming success.
Report only files changed by this run, with a description of each change.`;
}

function reviewPrompt(mode, args, preflight, implementations) {
    return `${sharedPrompt(mode, args)}

Review only this target's changes, considering the pre-existing worktree recorded
by preflight. Do not edit files. Report actionable findings with file and line.
Preflight: ${JSON.stringify(preflight)}
Implementation reports: ${JSON.stringify(implementations)}
Independently run the relevant tests in Docker and verify any required asset
compile. Give exact Docker commands, outcomes, and evidence. Report blocked if
checks cannot be run or relevant changes cannot be distinguished from existing
work. Do not claim a pass when findings, failed checks, or unverified required
checks remain.`;
}

function makeWorkflow(mode) {
    return {
        meta: {
            name: mode.name,
            description: mode.description,
            phases: [
                { title: "Preflight" },
                { title: "Implementation" },
                { title: "Review" },
                { title: "Fix" },
                { title: "Re-review" },
            ],
            argsSchema,
        },
        run: async (ctx) => {
            const args = readArgs(ctx.args);
            const result = {
                status: "blocked",
                target: args.target,
                category: mode.key,
                summary: "",
                preflight: null,
                implementations: [],
                reviews: [],
            };
            const stop = (status, summary) => ({ ...result, status, summary });

            ctx.phase("Preflight");
            result.preflight = (await ctx.agent(preflightPrompt(mode, args), {
                agent: "event-workspace-reviewer",
                label: "preflight",
                schema: preflightSchema,
            })) ?? null;
            let problem = preflightProblem(result.preflight);
            if (problem) {
                return stop("blocked", problem);
            }

            for (let round = 0; round < 2; round += 1) {
                ctx.phase(round === 0 ? "Implementation" : "Fix");
                const previousReview = round === 0 ? null : result.reviews[0];
                const implementation = (await ctx.agent(
                    implementationPrompt(mode, args, result.preflight, previousReview),
                    {
                        agent: "event-workspace-implementer",
                        label: round === 0 ? "implementation" : "fix",
                        schema: implementationSchema,
                    },
                )) ?? null;
                result.implementations.push(implementation);
                problem = implementationProblem(implementation);
                if (problem) {
                    return stop("blocked", problem);
                }

                ctx.phase(round === 0 ? "Review" : "Re-review");
                const review = (await ctx.agent(reviewPrompt(mode, args, result.preflight, result.implementations), {
                    agent: "event-workspace-reviewer",
                    label: round === 0 ? "review" : "re-review",
                    schema: reviewSchema,
                })) ?? null;
                result.reviews.push(review);
                problem = reviewProblem(review, result.implementations);
                if (problem) {
                    return stop("blocked", problem);
                }
                if (review.status === "passed" && review.findings.length === 0) {
                    return stop("verified", review.summary);
                }
                if (round === 1) {
                    return stop("changes_requested", "Review findings remain after one fix and re-review.");
                }
                ctx.log("Review requested changes; one bounded fix and re-review will follow.");
            }
            return stop("blocked", "The workflow ended without a review result.");
        },
    };
}

export function createWorkflows(defineWorkflow) {
    return modes.map((mode) => defineWorkflow(makeWorkflow(mode)));
}
