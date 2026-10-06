# Pull request guidance

Read this guide when preparing or updating a PR. Use the shared `file-pr` skill
when available; this guide also works without personal skills or a particular
agent provider. Repository safety and verification limits still apply.

## Scope and destination

Ordinary PRs target `main` unless the task explicitly specifies another base.

Create or push a PR only when requested. Keep one underlying problem per PR;
changes across components can belong together when they solve that problem.
Preserve unrelated local work. Check for an existing PR on the branch before
creating another. Use a ready-for-review PR unless a draft is explicitly wanted.
Link existing feedback or issues when relevant; creating a tracker item is not
a prerequisite.

## Explain the change

Use the repository's title convention and plain language about the outcome.
Describe the problem, what happens after the change, and any necessary scope or
tradeoff. A before/after example should explain the behavior to someone reviewing
the product without reading the implementation.

## Prove the claim

- Visible UI changes need before/after screenshots of the same scenario,
  viewport, theme, role, and fixture. Capture the baseline before editing when
  practical. For a new interface, label it as new and show its relevant states.
- Include a short recording when motion, timing, transitions, or an interaction
  cannot be demonstrated by screenshots. Give the reproduction steps.
- Backend, API, and CLI changes need focused tests or command output showing
  the changed behavior. For regression tests, verify each claimed scenario fails
  on the old behavior for its intended assertion and passes with the fix.
  A typecheck alone does not prove behavior.
- Performance claims need the revision or build, fixture, environment,
  measurement window, metric, and before/after results. Publish bounded
  aggregates and state measurement limits. Request counts do not prove CPU or
  battery improvements; simulator results do not establish physical-device results.
- Documentation and configuration changes need the relevant link, consistency,
  or command check. Run application checks only when they prove affected behavior.

Name the focused command or journey and its observed result. Cover relevant
entry points, clients, roles, and connection modes. Include failure and reverse
states when they belong to the change, such as denied access and revocation.
State untested paths and missing prerequisites explicitly.

Use isolated development state and synthetic or sanitized fixtures. Keep live
customer state, secrets, private data, and pairing credentials out of testing
and evidence. Prefer project browser or device tools when available. Before
computer use, state the exact visual or interaction question it will answer.
Respect existing authorization for services, builds, credentials, and external
writes. If required proof cannot be obtained, explain the missing evidence and
prerequisite in the PR and handoff; do not claim full verification.

Upload PR evidence through supported GitHub attachment tooling or an approved
project host. Verify that reviewers can open the links. Keep PR-only images,
recordings, and raw traces out of Git. Explain an unavailable baseline.

## Risk and attribution

Describe material merge risks, affected users or consumers, migrations, and
recovery steps. Distinguish a code revert from external effects it cannot undo.
Keep this brief for straightforward changes.

If an agent did the work, end the description with its actual model and harness.
When supported, register the PR with the current orchestration thread. Re-read
the rendered description and evidence links after publishing.
