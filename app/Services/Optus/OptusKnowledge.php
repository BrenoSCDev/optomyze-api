<?php

namespace App\Services\Optus;

/**
 * OPTUS KNOWLEDGE BASE
 *
 * What Optus is allowed to claim about Optomyze CRM. Everything the assistant
 * says about the product has to be traceable to this file.
 *
 * The rule for editing it: only write down what you have confirmed in the code
 * on the branch that ships. A feature that exists on a working branch does not
 * exist for the user, and describing one is the single most damaging thing
 * this assistant can do — it sends people hunting for menus that aren't there.
 * The workflow/automation builder is the live example: it is real, it is on
 * `feature/automation-builder`, and it is deliberately absent below.
 *
 * Kept as one document rather than per-topic chunks because it is small enough
 * to send whole. When it outgrows the context window, split it here and select
 * sections by the user's question — nothing outside this class needs to change.
 */
class OptusKnowledge
{
    /**
     * Bumped whenever the document changes. Part of the prompt cache key, so
     * an edit here takes effect without a manual cache flush.
     */
    public const VERSION = 3;

    public static function document(): string
    {
        return <<<'MARKDOWN'
# Optomyze CRM — product knowledge

## What Optomyze is
A multi-tenant B2B CRM. A **company** is the tenant. Every record — leads, deals,
funnels, tasks, conversations — belongs to exactly one company and is never
visible from another.

A person signs in with an email and password and lands in one company. The same
email can hold accounts in several companies; those accounts are separate, with
their own role and their own data. Switching company means signing into that
company's account, not toggling a filter.

## Navigation

The sidebar is the primary navigation. It can be collapsed to an icon rail, and
its collapsed/expanded state is remembered between sessions.

**Direct links** (always one click):
- **Contacts** — every contact in the company.
- **Inbox** — the Global Inbox, all customer conversations.
- **Calendar** — appointments.
- **App Store** — integrations and widgets.

**Expandable sections:**
- **Dashboards** — Optomyze Metrics, Plan Usage, Meta Ads, Google Ads.
- **Funnels** — every active funnel, split into Sales Funnels and Deal Funnels.
- **Activity** — Record Logs, Tasks.
- **Automations** — Manage Agents (AI Agents, in Beta).
- **API** — API Tokens, Allowed Origins, API Request Logs, API Setup.
- **Organization** — Users, Teams, Optomyze Teams (internal team chat).
- **Products** — Manage Products.

**Settings** (from the settings menu): CRM Panel, Business Profile, Companies,
Preferences. Companies is hidden from the Agent role.

## Contacts and Leads — the same record

This is the most common point of confusion, so be precise:

In Optomyze, a **Lead** and a **Contact** are the *same underlying record*, seen
two ways.

- **Contacts** is the canonical list — every person the company holds, whether
  or not they are in a funnel.
- A **funnel board** is an optional view over a subset of that list: the
  contacts that have been placed in that funnel's stages.

A contact does **not** have to belong to a funnel or a stage. A contact that
isn't in any funnel still appears in Contacts; it simply has no pipeline
position. "Adding a contact to a funnel" gives an existing record a funnel and
stage — it does not create a second record.

Practical consequence: deleting a contact from Contacts removes the person.
Removing them from a funnel only removes their pipeline position.

## Funnels (pipelines)

A **funnel** is a pipeline: an ordered set of **stages** that a record moves
through. Funnels come in two types, and the sidebar lists them separately:

- **Sales Funnels** (contact funnels) — hold **contacts/leads**.
- **Deal Funnels** — hold **deals**.

Stages are ordered and belong to one funnel. Moving a record between stages is
done by dragging its card on the board, or from the record's own view via the
stage journey control.

Boards load in batches per stage and fetch more as you scroll, so a stage with
many records is not loaded all at once. Filtering and sorting on a board are
applied across the whole stage, not just the part already loaded.

## Deals

A **deal** is an opportunity with a monetary value, held in a Deal Funnel. A
deal is normally linked to the contact it belongs to.

A deal record carries: name, value/total, currency, pricing model, status,
funnel and stage, an owner, and its linked contact.

Deal detail view includes tabs for **Notes**, **Tasks**, **Appointments** and
**Conversation**. Appointments and Conversation belong to the *contact* behind
the deal — you meet a person, not an opportunity — so they show that contact's
appointments and message history. Notes and Tasks belong to the deal itself.

## Tasks

Tasks live in a three-level structure: **Space → List → Task**.

- Each **List** has its own set of **statuses**, seeded with a working default
  set when the list is created. Exactly one status is the default landing
  column for new tasks.
- Tasks support: assignees, tags, priority, due and start dates, subtasks
  (one level deep), checklists, and comments with @mentions.
- A task can be linked to a **contact** or to a **deal**. Linked tasks appear on
  that record's Tasks tab.
- Tasks can be archived; archived tasks are hidden by default.

Tasks are reachable from **Activity → Tasks**, and from the Tasks tab on a
contact or deal.

## Notes

Notes are collaborative and threaded, on both contacts and deals.

- Threads are **one level deep**: you reply to a note, and replies to a reply
  join the same thread rather than nesting further.
- A note records its author and time, and is marked as edited if changed.
- Only the author can edit their own note. The author can delete it; admins and
  managers can delete anyone's.
- Deleting a note that has replies deletes the whole thread.
- @mentions of teammates are supported in the note body.

## Conversations and the Global Inbox

The **Inbox** holds customer conversations across channels: **WhatsApp**,
**Instagram**, **Messenger**, **SMS**, and the **website chat widget**.

- Conversations can be marked read/unread, and filtered by all / unread / read.
- Conversations can be **archived** (hidden by default, reachable via filter)
  and **favourited** (pinned to the top).
- A conversation is normally tied to the contact it came from.

**Optomyze Teams** (under Organization) is separate: it is internal chat between
users of the same company, not customer messaging.

## Calendar and Appointments

**Appointments** are scheduled meetings, shown on the **Calendar**. Appointments
have a type (appointment types are configurable). An appointment is linked to
the contact it is with, and appears on that contact's Appointments tab and on
the Appointments tab of that contact's deals.

## AI Agents (Beta)

Under **Automations → Manage Agents**. An AI Agent answers customer messages on
a channel automatically. Agents are configured per company with their own OpenAI
integration, a system prompt, the channels they cover, and which native tools
they may use (for example: change a lead's stage, add a note, manage tags,
search products, hand over to a human).

This is customer-facing automation. It is a different feature from Optus.

## Products

**Products** under Manage Products. Products can be attached to deals, which is
how a deal with a product-based pricing model gets its value.

## App Store

Integrations and widgets are installed from the **App Store**. Widgets include a
**website chat widget** (live chat that lands in the Global Inbox) and a
**website form widget** (a form that creates contacts). Both are installed
first, then configured, then embedded on a site with a snippet.

## Connecting a messaging channel

Every messaging channel is connected the same way: **App Store** → find the app
→ **Install** → **Configure**. Once connected, incoming messages land in the
**Global Inbox** and create or match a contact.

- **WhatsApp** connects through **Evolution API**. Configure asks for the
  Evolution **Base URL** and **API Key**, then you create an **instance** (give
  it a name) and link the phone by scanning the **QR code** from WhatsApp →
  Linked devices. The instance shows as connected once the phone is paired, and
  can be disconnected or deleted later.
- **Instagram** requires an **Instagram Business account** and a Meta **access
  token**, pasted in Configure. Optomyze then verifies the connection.
- **Facebook Messenger** works the same way for a **Facebook Page**: paste the
  Page access token in Configure and connect.
- For Instagram and Messenger you also pick the **funnel** new conversations
  should create their contacts in.
- The **website chat widget** is not a social app — it is a widget, installed
  from the App Store and embedded on a site, and it also lands in the Inbox.

Connecting a channel is an admin-level setup task, and Optus cannot do it for
the user — it can only walk them through these screens.

## Users, roles and permissions

Three roles: **Admin**, **Manager**, **Agent**.

- **Admin** — full access, including company settings.
- **Manager** — can manage users; cannot manage company settings.
- **Agent** — day-to-day CRM use. Cannot manage users. The Companies settings
  item is hidden.

Users and Teams are managed under **Organization**.

Record visibility can be narrower than the company: some records are scoped to
the user they are assigned to. If someone cannot see or edit a record, the usual
causes are (a) it is assigned to another user, (b) their role lacks the
permission, or (c) the record is archived.

## Tags

Tags are colour-coded labels. A tag declares which entities it applies to —
contact, deal, or task — so a tag picker only offers tags valid for that record.

## Record Logs

**Activity → Record Logs** is the audit trail: what changed on a record, when,
and who did it.

## API

Under **API**: API Tokens (create/revoke), Allowed Origins (CORS allow-list),
API Request Logs, and API Setup. This is for integrating external systems.

## Preferences

**Settings → Preferences** holds per-user options, including the language and
the toggle that shows or hides the Optus assistant bubble.

## Language

The interface is available in English, Portuguese, German and Spanish, chosen
per user in Preferences.

## Common workflows

These are the verified entry points. Where a control is named below, that is its
exact label; where none is named, describe the screen to go to and let the user
find the control rather than inventing a name for it.

**Create a contact** — Go to **Contacts**, then **New contact**.

**Put a contact into a funnel** — From **Contacts**, use the row's
**Add to a funnel** action, or select several contacts and use the bulk
**Add to funnel** action. This gives an existing contact a funnel and stage.

**Create a deal** — Open the Deal Funnel you want it in from **Funnels**, and
create the deal from that board. A deal needs the contact it belongs to.

**Move a record through a pipeline** — Drag its card between stages on the
board, or open the record and use its stage journey control.

**Add a task to a contact or deal** — Open the record and use its **Tasks** tab.
Tasks can also be created from **Activity → Tasks**.

**Add a note** — Open the contact or deal and use its **Notes** tab. Reply to a
note to start a thread.

**Reply to a customer** — Open **Inbox**, pick the conversation, and reply on
whichever channel it arrived on.

**Install a widget or integration** — Go to **App Store**, install it, configure
it, then embed the snippet it gives you.

**Change your language** — Settings → **Preferences**.
MARKDOWN;
    }
}
