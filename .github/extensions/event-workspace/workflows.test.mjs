import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { registerHooks } from "node:module";
import test from "node:test";
import { createWorkflows } from "./workflows.mjs";

const workflows = createWorkflows((definition) => definition);
const surface = workflows.find((workflow) => workflow.meta.name === "event-workspace-migrate-surface");
const viewer = workflows.find((workflow) => workflow.meta.name === "event-workspace-add-viewer");
const prerequisites = [
    "one_target",
    "shared_contract",
    "generic_preview",
    "references",
    "source_adapter",
    "access_policy",
    "worktree",
];

function preflight(overrides = {}) {
    return {
        status: "ready",
        summary: "The shared foundation and a safe single target are present.",
        checks: Object.fromEntries(prerequisites.map((name) => [name, {
            status: "pass",
            evidence: `Evidence for ${name}`,
        }])),
        ...overrides,
    };
}

function checks() {
    return [{
        kind: "test",
        command: "docker compose exec php bin/phpunit tests/ExampleTest.php",
        status: "passed",
        evidence: "1 test passed",
    }];
}

function implementation(overrides = {}) {
    return {
        status: "completed",
        summary: "The target is migrated.",
        files: [{ path: "src/Example.php", change: "Use the shared preview." }],
        checks: checks(),
        ...overrides,
    };
}

function review(overrides = {}) {
    return {
        status: "passed",
        summary: "The change and focused tests passed review.",
        findings: [],
        checks: checks(),
        ...overrides,
    };
}

function finding() {
    return {
        severity: "high",
        path: "src/Example.php",
        line: "42",
        detail: "A missing event is dropped rather than shown as unresolved.",
    };
}

function context(replies, args = { target: "Reading Nook" }) {
    const calls = [];
    const phases = [];
    const logs = [];
    return {
        args,
        calls,
        phases,
        logs,
        phase: (name) => phases.push(name),
        log: (message) => logs.push(message),
        agent: async (prompt, options) => {
            calls.push({ prompt, options });
            return replies.shift();
        },
    };
}

test("the entrypoint registers both workflows with the host SDK", async () => {
    const stub = `export const defineWorkflow = (definition) => definition;
export const joinSession = async (config) => { globalThis.__workspaceRegistration = config; };`;
    const sdkUrl = `data:text/javascript,${encodeURIComponent(stub)}`;
    const hooks = registerHooks({
        resolve(specifier, nextContext, nextResolve) {
            if (specifier === "@github/copilot-sdk/extension") {
                return { url: sdkUrl, shortCircuit: true };
            }
            return nextResolve(specifier, nextContext);
        },
    });
    try {
        await import("./extension.mjs?registration-test");
        assert.deepEqual(
            globalThis.__workspaceRegistration.workflows.map((workflow) => workflow.meta.name),
            ["event-workspace-migrate-surface", "event-workspace-add-viewer"],
        );
    } finally {
        hooks.deregister();
        delete globalThis.__workspaceRegistration;
    }
});

test("each workflow declares a single target and no guessed resource limits", () => {
    for (const workflow of workflows) {
        assert.deepEqual(workflow.meta.argsSchema.required, ["target"]);
        assert.deepEqual(Object.keys(workflow.meta.argsSchema.properties), ["target", "notes"]);
        assert.equal(workflow.meta.limits, undefined);
        assert.deepEqual(workflow.meta.phases.map((phase) => phase.title), [
            "Preflight", "Implementation", "Review", "Fix", "Re-review",
        ]);
    }
});

test("the selected agent names match their repository profiles", async () => {
    const implementer = await readFile(new URL("../../agents/event-workspace-implementer.agent.md", import.meta.url), "utf8");
    const reviewer = await readFile(new URL("../../agents/event-workspace-reviewer.agent.md", import.meta.url), "utf8");
    assert.match(implementer, /^name: event-workspace-implementer$/m);
    assert.match(reviewer, /^name: event-workspace-reviewer$/m);
    assert.match(reviewer, /^tools: \["read", "search", "execute"\]$/m);
});

test("invalid arguments fail before launching an agent", async () => {
    const invalid = [
        null,
        [],
        {},
        { target: "" },
        { target: "first\nsecond" },
        { target: "x".repeat(201) },
        { target: "Reading Nook", notes: 1 },
        { target: "Reading Nook", notes: "x".repeat(4001) },
        { target: "Reading Nook", targets: ["My Content"] },
    ];
    for (const args of invalid) {
        const ctx = context([], args);
        await assert.rejects(() => surface.run(ctx));
        assert.equal(ctx.calls.length, 0);
    }
});

test("a missing or blocked preflight never starts implementation", async () => {
    for (const report of [
        null,
        preflight({ status: "blocked", summary: "The shared preview is not implemented." }),
        preflight({
            checks: {
                ...preflight().checks,
                generic_preview: { status: "fail", evidence: "No generic preview exists." },
            },
        }),
        preflight({
            checks: {
                ...preflight().checks,
                worktree: { status: "pass", evidence: "" },
            },
        }),
    ]) {
        const ctx = context([report]);
        const result = await surface.run(ctx);
        assert.equal(result.status, "blocked");
        assert.equal(ctx.calls.length, 1);
        assert.equal(result.implementations.length, 0);
        assert.doesNotThrow(() => JSON.stringify(result));
    }
});

test("a surface passes only after implementation and independent review", async () => {
    const ctx = context([preflight(), implementation(), review()], {
        target: " Reading Nook ",
        notes: "Preserve the action menu.",
    });
    const result = await surface.run(ctx);
    assert.equal(result.status, "verified");
    assert.equal(result.target, "Reading Nook");
    assert.deepEqual(ctx.phases, ["Preflight", "Implementation", "Review"]);
    assert.deepEqual(ctx.calls.map((call) => call.options.agent), [
        "event-workspace-reviewer",
        "event-workspace-implementer",
        "event-workspace-reviewer",
    ]);
    assert.deepEqual(ctx.calls.map((call) => call.options.label), ["preflight", "implementation", "review"]);
    assert.match(ctx.calls[0].prompt, /migrate-event-surface\/SKILL\.md/);
    assert.match(ctx.calls[1].prompt, /Preserve the action menu/);
    assert.match(ctx.calls[2].prompt, /Independently run the relevant tests in Docker/);
    assert.equal(result.implementations.length, 1);
    assert.equal(result.reviews.length, 1);
});

test("the viewer workflow uses its own skill without selecting another target", async () => {
    const ctx = context([preflight(), implementation(), review()], { target: "Follow-list profile cards" });
    const result = await viewer.run(ctx);
    assert.equal(result.status, "verified");
    assert.equal(result.category, "viewer");
    assert.equal(result.target, "Follow-list profile cards");
    assert.match(ctx.calls[0].prompt, /add-event-viewer\/SKILL\.md/);
    assert.match(ctx.calls[1].prompt, /without creating a separate event pipeline/);
    assert.equal(ctx.calls.length, 3);
});

test("missing, blocked, or unverified implementations never reach review", async () => {
    const reports = [
        null,
        implementation({ status: "blocked", summary: "Docker is unavailable." }),
        implementation({ files: [] }),
        implementation({ checks: [] }),
        implementation({ checks: [{ ...checks()[0], status: "failed" }] }),
        implementation({ checks: [{ ...checks()[0], status: "not_run" }] }),
        implementation({ checks: [{ ...checks()[0], command: "bin/phpunit tests/ExampleTest.php" }] }),
        implementation({ checks: [{ ...checks()[0], evidence: "" }] }),
        implementation({ checks: [{ ...checks()[0], kind: "compile" }] }),
    ];
    for (const report of reports) {
        const ctx = context([preflight(), report]);
        const result = await surface.run(ctx);
        assert.equal(result.status, "blocked");
        assert.equal(ctx.calls.length, 2);
        assert.equal(result.reviews.length, 0);
    }
});

test("changed application assets require compilation in both implementation and review", async () => {
    const changedAssets = implementation({
        files: [{ path: "C:\\newsroom\\assets\\controllers\\workspace_controller.js", change: "Add disclosure behavior." }],
    });
    const withoutCompile = context([preflight(), changedAssets]);
    const implementationResult = await surface.run(withoutCompile);
    assert.equal(implementationResult.status, "blocked");
    assert.match(implementationResult.summary, /asset-map:compile/);
    assert.equal(withoutCompile.calls.length, 2);

    const compile = {
        kind: "compile",
        command: "docker.exe compose exec php bin/console asset-map:compile",
        status: "passed",
        evidence: "Asset map compiled",
    };
    const compiled = implementation({
        files: changedAssets.files,
        checks: [...checks(), compile],
    });
    const missingReviewCompile = context([preflight(), compiled, review()]);
    const reviewResult = await surface.run(missingReviewCompile);
    assert.equal(reviewResult.status, "blocked");
    assert.match(reviewResult.summary, /asset-map:compile/);

    const complete = context([preflight(), compiled, review({ checks: [...checks(), compile] })]);
    assert.equal((await surface.run(complete)).status, "verified");
});

test("a fix that changes assets requires the final review to compile them", async () => {
    const fix = implementation({
        files: [{ path: "assets/styles/workspace.css", change: "Fix the reviewed layout." }],
        checks: [...checks(), {
            kind: "compile",
            command: "docker compose exec php bin/console asset-map:compile",
            status: "passed",
            evidence: "Asset map compiled",
        }],
    });
    const ctx = context([
        preflight(),
        implementation(),
        review({ status: "changes_requested", findings: [finding()] }),
        fix,
        review(),
    ]);
    const result = await surface.run(ctx);
    assert.equal(result.status, "blocked");
    assert.match(result.summary, /asset-map:compile/);
    assert.equal(ctx.calls.length, 5);
});

test("missing, blocked, or unverified reviews cannot pass", async () => {
    const reports = [
        null,
        review({ status: "blocked", summary: "Cannot isolate pre-existing edits." }),
        review({ checks: [] }),
        review({ checks: [{ ...checks()[0], status: "failed" }] }),
        review({ checks: [{ ...checks()[0], command: "bin/phpunit tests/ExampleTest.php" }] }),
        review({ status: "changes_requested", findings: [] }),
        review({ findings: [{ ...finding(), line: "" }] }),
    ];
    for (const report of reports) {
        const ctx = context([preflight(), implementation(), report]);
        const result = await surface.run(ctx);
        assert.equal(result.status, "blocked");
        assert.equal(ctx.calls.length, 3);
    }
});

test("findings trigger one fix and a fresh review even if the first verdict says passed", async () => {
    const firstReview = review({ status: "passed", findings: [finding()] });
    const fix = implementation({
        summary: "Preserved unresolved references.",
        files: [{ path: "src/Example.php", change: "Keep missing occurrences visible." }],
    });
    const ctx = context([preflight(), implementation(), firstReview, fix, review()]);
    const result = await surface.run(ctx);
    assert.equal(result.status, "verified");
    assert.deepEqual(ctx.phases, ["Preflight", "Implementation", "Review", "Fix", "Re-review"]);
    assert.deepEqual(ctx.calls.map((call) => call.options.label), [
        "preflight", "implementation", "review", "fix", "re-review",
    ]);
    assert.match(ctx.calls[3].prompt, /A missing event is dropped/);
    assert.equal(result.implementations.length, 2);
    assert.equal(result.reviews.length, 2);
    assert.equal(ctx.logs.length, 1);
});

test("remaining findings after the one fix are reported without another agent", async () => {
    const changes = review({ status: "changes_requested", findings: [finding()] });
    const ctx = context([preflight(), implementation(), changes, implementation(), changes]);
    const result = await surface.run(ctx);
    assert.equal(result.status, "changes_requested");
    assert.equal(ctx.calls.length, 5);
    assert.equal(result.reviews.length, 2);
});

test("a failed fix blocks before re-review", async () => {
    const changes = review({ status: "changes_requested", findings: [finding()] });
    const fix = implementation({ status: "blocked", summary: "The fix needs a design decision." });
    const ctx = context([preflight(), implementation(), changes, fix]);
    const result = await surface.run(ctx);
    assert.equal(result.status, "blocked");
    assert.match(result.summary, /design decision/);
    assert.equal(ctx.calls.length, 4);
});
