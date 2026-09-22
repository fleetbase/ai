> v0.0.5 ~ "Answers from the docs, console actions, and a new log viewer"

---
## Highlights

- **Answers come from the Fleetbase documentation.** fleetbase.io/docs is indexed (with a packaged snapshot for offline and self-hosted installs), and how-to answers cite the page they used instead of guessing menu paths. `ai:sync-docs` refreshes the index weekly, and the new **Knowledge Base** admin page shows its status.
- **Fleetbase AI can take you there.** The assistant proposes console actions such as "go to **IAM › Users** and open **New User**" as a card; nothing runs until you press Go, and the server re-checks permissions first. Actions come from an allowlisted registry that engines extend (Fleet-Ops adds its own).
- **Tool calling with Anthropic or OpenAI.** Answers are built over several steps with real conversation history, record counting and listing tools, and prompt caching. Order drafts, route optimization proposals and import guidance still appear as preview cards to confirm.
- **Redesigned Task & Chat Logs.** A full-height split view: compact filters that apply as you type, a conversation list that flags negative feedback, failures and degraded or cut-off answers, and each conversation read as a transcript with its tool steps on demand. **Reveal Content is removed** — anyone with `ai view audit logs` sees full conversations and can export them; exports use the same filters as the list and are still access-logged.
- **Redesigned Usage Analytics.** Period presets, formatted headline numbers (conversations, success rate, not helpful, degraded and cut off), answers and tokens per day on a chart, and Who/What rankings you can click to filter.

---
## Fixes

- Confirming a console action no longer fails with "This AI action was not found". Task and session lookups matched a UUID such as `4dcd1b1f-…` against the numeric id 4 and could act on the wrong task.
- Inline code in answers no longer shows as `@@AICODE0@@`, and documentation links in answers are clickable.
- Console labels match the console: **New User**, **New Group**, **New API Key**.
- Capabilities that no tool definition can reach are reported in the audit log instead of silently disappearing.
- The Fleet-Ops `search_resources` tool no longer fails on every call (fleetbase/fleetops).

---
## Testing

- 166 server tests (Pest) and 24 Ember tests. The Ember suite can run for the first time: the dummy app was missing `@ember/legacy-built-in-components` and `tracked-built-ins`.
- `composer test` runs lint, phpstan and Pest end to end; existing phpstan findings are baselined.
- Full-bleed pages need fleetbase/fleetbase#671; on an older console the log and analytics views work inside the boxed panel.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
