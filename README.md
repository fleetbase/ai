# Fleetbase AI

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
fleetbase extension:install fleetbase/ai
```

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
- Action preview cards, such as Fleet-Ops create-order previews.

Closing the prompt only hides it. A chat session continues until the user starts a new chat, ends the current chat, or deletes the session from history.

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

## Development Checks

Frontend checks:

```bash
./node_modules/.bin/eslint addon/services/ai.js addon/components/ai-prompt.js
./node_modules/.bin/ember-template-lint addon/components/ai-prompt.hbs
./node_modules/.bin/stylelint addon/styles/ai-engine.css
```

Backend checks:

```bash
php -l server/src/routes.php
php -l server/src/Http/Controllers/Internal/AiSessionController.php
```

The local template-lint configuration may print `Invalid rule configuration found: no-down-event-binding` while still exiting successfully.

## Notes

- Billing and SaaS metering are intentionally outside this module.
- Token usage is retained as provider telemetry.
- Session deletion is a soft delete; task and step records are retained for auditability.
- User-facing copy should use `Fleet-Ops` for the operations module name.
