# Jev decision record workflow

Use the installed [TypeSafe skill](../../.agents/skills/typesafe-ai/SKILL.md) and [live TypeSafe documentation](https://docs.typesafe.ai/llms.txt) when a bounded semantic judgment would help a Newsroom decision. Jev gives a typed second opinion; the repository evidence and maintainer own the final decision.

## Sharing scope

The maintainer has authorized sharing this open source project's **public source and architectural context** with TypeSafe's Jev for project work. This includes concise descriptions of public code paths and documented behavior. Exclude environment variable values, API keys, tokens, private logs, production database contents, personal data, and unpublished user content. Inspect the exact outbound state before a call. Codex sandbox, network, and approval controls still apply; this statement does not override a denied tool action.

Keep the TYPESAFE_API_KEY value in the container environment. Never put its value in a prompt, decision record, tracked file, or tool output. If automatic approval review blocks a call, use the permitted review path or request approval for the exact reduced payload.

## Decision steps

1. Gather observable repository and operational evidence. Name missing or unreliable measurements.
2. Define a small set of genuine options and the question being judged. Use [Choice](https://docs.typesafe.ai/primitives/choice) for one option, [Noul](https://docs.typesafe.ai/primitives/noul) for a yes/no condition, or [Score](https://docs.typesafe.ai/primitives/score) for an ordered degree. Check current API and question guidance before calling.
3. Send only the public context needed for that question. Record the sent summary, model version, options, returned distribution and confidence, and any errors or cost information available.
4. Compare Jev's result with code and operational evidence. Choice confidence describes how concentrated the option distribution is; it is not proof that the choice is correct.
5. Write the human decision, assumptions, implementation work, and validation plan in the relevant feature document. Revisit the decision if new evidence changes an assumption.

## Record template

- **Question and options:** What decision was presented?
- **Evidence:** What was directly observed, and what remains uncertain?
- **Shared state:** The exact public summary sent to Jev, with no secrets or private data.
- **Jev result:** Date, model version, typed answers, probabilities, confidence, and usage if available.
- **Human decision:** Choice, reason, constraints, and follow-up measurements.
- **Validation:** What outcome will be checked after implementation?

The [Active Indexing removal plan](../Newsroom/active-indexing-removal-plan.md) is the first example. Its reported lack of article contribution is explicitly an operator observation because the existing counter does not measure it.
