# Fleetbase AI

[![Coverage](https://codecov.io/gh/fleetbase/ai/branch/main/graph/badge.svg)](https://codecov.io/gh/fleetbase/ai)

Fleetbase AI is an operations copilot and task automation module for Fleetbase. It adds a global AI prompt to the Fleetbase console, records each AI turn as an auditable task, and gives Fleetbase extensions a capability framework for exposing safe AI-readable context and previewable actions.

This extension was scaffolded with the Fleetbase CLI and contains both the Ember engine and Laravel backend package for `fleetbase/ai`.

## Current Status

This module is in active development. The current version includes:

- A global floating Fleetbase AI prompt opened from the header tray.
- Persistent chat sessions with history, continuation, ending, and soft-delete support.
- System-wide admin configuration for enabling Fleetbase AI and selecting a provider/model.
- OpenAI, Claude, and Local Preview provider support.
- Durable `ai_tasks`, `ai_task_steps`, and `ai_sessions` recording.
- Markdown response rendering, including lists, links, inline code, and simple tables.
- Attachment upload references for AI turns.
- A capability registry for modules to register read/context capabilities and preview/apply actions.
- Fleet-Ops pilot capabilities, including order context/insights and a compact create-order preview flow.

## Installation

Install this extension like any Fleetbase extension package:

```bash
flb install fleetbase/ai --path /path/to/fleetbase
```

The `--path` value should point to the Fleetbase instance directory containing both `console/` and `api/`.

For local development inside a Fleetbase workspace, ensure the console workspace includes `@fleetbase/ai-engine`, then rebuild the console assets after frontend changes.

## Admin Configuration

Fleetbase AI is configured system-wide by admins. Provider keys are not stored per company.

In the console admin settings:

1. Open **AI > Provider Settings**.
2. Enable Fleetbase AI.
3. Select a provider.
4. Select the default model from the backend-supported model list.
5. Enter provider credentials for the selected provider.
6. Save and test the provider.

Supported providers:

- **Local Preview**: local non-network placeholder provider for development and UI testing.
- **OpenAI**: Responses API integration.
- **Claude**: Anthropic Messages API integration.

Base URLs are treated as advanced settings and default to the canonical provider API endpoints.

## Console Experience

When enabled, Fleetbase AI registers a compact magic-wand tray button in the Fleetbase console header. The same prompt can be opened globally with the configured keyboard shortcut.

The prompt supports:

- Persistent sessions instead of throwaway one-off prompts.
- Multi-line expanding input.
- User and AI turns in the transcript.
- Session history with delete controls.
- Uploaded file references for future capability-specific parsing.
- Action preview cards, such as Fleet-Ops create-order previews (several per answer).
- Confirmation cards for console actions the AI offers, such as opening a page or a create dialog.
- Thumbs up/down feedback on answers.

Closing the prompt only hides it. A chat session continues until the user starts a new chat, ends the current chat, or deletes the session from history.

## How Answers Are Grounded

With OpenAI or Claude enabled, each prompt runs as a tool-calling conversation. The model looks information up before answering instead of relying on memory:

- **Documentation**: `search_docs` and `read_doc` search the official Fleetbase documentation (fleetbase.io/docs). Answers link the pages they used.
- **Company data**: `count_records`, `group_count`, and `list_records` query the allowlisted resources extensions register, scoped to the organization and the user's permissions. Invalid filters return errors instead of being ignored.
- **Console actions**: `find_console_commands` and `propose_console_command` offer to open a page or a dialog (for example IAM › Users › Create user). The user sees a confirmation card and nothing runs until they confirm; the server re-checks permissions at that moment.
- **Extension tools**: modules add their own, such as Fleet-Ops record search and order drafts.

Every tool call is recorded as a task step. Turning off **Look up answers with tools** in the provider settings falls back to the older single-request mode.

### Who sees what

Only users whose `type` is `admin` are system administrators. Everyone else, including organization "Administrator" roles, is answered as an organization user: documentation pages and sections about system setup, service credentials, environment variables, and self-hosting are filtered out on the server, admin console actions are never offered, and the system prompt tells the model to refer those needs to the system administrator.

### Documentation index

Documentation is indexed from the fleetbase.io sitemap into `ai_knowledge_documents` and `ai_knowledge_chunks` (MySQL full-text search), refreshed weekly, and tagged by audience (`server/config/ai.php` → `knowledge.docs`). A snapshot ships in `server/resources/ai-knowledge` and loads automatically when nothing is indexed, so instances without internet access still have documentation.

```bash
php artisan ai:sync-docs                     # crawl fleetbase.io/docs
php artisan ai:sync-docs --snapshot          # load the packaged snapshot
php artisan ai:sync-docs --write-snapshot=server/resources/ai-knowledge/docs-snapshot.json.gz
```

System administrators can check the index and trigger a sync in **Admin → AI Config → Knowledge Base**.

## Capability Framework

Fleetbase AI does not give providers arbitrary database access. Modules expose AI functionality explicitly by registering capabilities.

Capabilities can provide:

- Read/context data for prompts.
- Preview-only actions.
- Confirmed apply actions.
- Permission metadata.
- Module-specific UI preview components.

Fleet-Ops is the first pilot module using this framework. Its initial capabilities are intentionally conservative: read/report context and preview-confirm-apply actions rather than silent mutations.

## Fleet-Ops Pilot

The Fleet-Ops pilot currently focuses on useful operational workflows:

- Answering questions about orders and operational resources.
- Producing bounded order insights and simple reports.
- Returning docs/help context for Fleet-Ops workflows.
- Previewing Fleet-Ops order creation from natural-language prompts.

Create-order actions use a dedicated compact preview component designed for the AI prompt. The preview can show pickup/dropoff details, a small route preview, driver/vehicle assignment fields, POD and dispatch toggles, notes, and explicit create/cancel controls.

Orders are not created until the user confirms the preview.

## Console Commands for Extensions

Extensions register console actions from their service providers. Definitions are plain arrays, so an extension does not need to depend on this package's classes:

```php
$this->callAfterResolving(\Fleetbase\Ai\Support\Commands\AiCommandRegistry::class, function ($commands) {
    $commands->registerMany([[
        'id'          => 'my-extension.widgets.create',
        'label'       => 'Create widget',
        'breadcrumb'  => 'My Extension › Widgets',
        'description' => 'Open the new widget form.',
        'steps'       => [
            ['type' => 'navigate', 'route' => 'console.my-extension.widgets.index'],
            ['type' => 'service', 'engine' => '@vendor/my-extension-engine', 'service' => 'widget-actions', 'method' => 'modal.create'],
        ],
        'permissions' => ['my-extension create widget'],
    ]]);
});
```

`service` steps call a method on the engine's resource-action service, so the same dialog opens from the AI prompt as from the extension's own pages. Verify routes and service methods resolve with:

```bash
node scripts/verify-ai-commands.mjs --console ../../console --packages ..
```

## Logs, Feedback, and Evaluation

- Users rate answers with thumbs up or down. Ratings are filterable in **Admin → AI Config → Task & Chat Logs**, alongside degraded answers (a capability failed) and cut-off answers.
- The log view shows each conversation in full: every prompt and answer, and, per answer, every step including each tool call's arguments and results and the exact system prompt and messages sent to the model. It requires the `ai view audit logs` permission.
- Export logs from the admin view, or with `php artisan ai:export-logs --from=2026-09-01 --format=jsonl`. Exports use the same filters as the log view, contain user content, require `ai view audit logs`, and are recorded in the access log.
- `php artisan ai:eval` runs the golden cases in `server/resources/ai-eval/cases.json` (drawn from real conversations) against the configured provider and reports a pass rate. It calls the provider, so it asks for confirmation; use `--model` to compare models.
- `php artisan ai:replay <task-uuid>` re-runs a recorded turn through the current runtime and prints both answers.

## Development Checks

Frontend checks:

```bash
./node_modules/.bin/eslint addon/services/ai.js addon/components/ai-prompt.js
./node_modules/.bin/ember-template-lint addon/components/ai-prompt.hbs
./node_modules/.bin/stylelint addon/styles/ai-engine.css
```

Backend checks:

```bash
composer test:unit
composer test:lint
```

The local template-lint configuration may print `Invalid rule configuration found: no-down-event-binding` while still exiting successfully.

## Notes

- Billing and SaaS metering are intentionally outside this module.
- Token usage is retained as provider telemetry.
- Session deletion is a soft delete; task and step records are retained for auditability.
- User-facing copy should use `Fleet-Ops` for the operations module name.
