# BW Outdated Pages (TYPO3 13.4+)

Three tools in one extension for keeping editorial content up to date:

- **Scheduler task** — checks a configurable branch of the page tree for
  outdated pages and sends a summary email. Multiple task instances can run
  in parallel, each with its own entry point, threshold and recipients — e.g.
  one per department or site section.
- **Dashboard widget** — shows a live, clickable overview of outdated pages
  across all page tree branches the currently logged-in backend user has
  access to (based on their web mounts), independent of any scheduler task
  configuration.
- **Backend module** — an ad-hoc check scoped to whichever page is selected
  in the page tree, with adjustable threshold and filters. Useful for
  reviewing a specific branch on demand without setting up a scheduler task.

## What counts as "outdated"

A page is considered outdated only if **both** of the following are true:

- the page's own `tstamp` (page properties) is older than the configured
  threshold, **and**
- every non-hidden content element on that page is also older than the
  threshold.

TYPO3 does not automatically bump `pages.tstamp` when only a content
element is edited, so the task explicitly checks
`MAX(tt_content.tstamp)` per page as well and uses whichever of the two
values is more recent. A page whose content was edited last week, but
whose page properties haven't been touched in years, is correctly
**not** flagged as outdated.

### Every configured language is checked independently

For every page in the checked branch, **each language configured for
the site** is evaluated separately - not just a fixed pair. A page's
French translation can be outdated while its Italian version is up to
date, or vice versa, and each is reported individually. Content
elements always carry the default-language page's UID as their `pid`
in TYPO3 (regardless of which language they belong to), so this is
taken into account correctly rather than just checking the
default-language page's own content.

- Languages are read directly from the site configuration
  (`config/sites/<site>/config.yaml`) - however many are configured,
  with no limit and no hardcoded language UIDs, which can differ
  between installations.
- If a page has no translation into a given language, that language is
  simply skipped for that page (nothing to check/report).
- If no site configuration can be determined for the entry page (e.g.
  it isn't part of a configured site yet), the task falls back to
  checking only the default-language (`sys_language_uid = 0`) version.
- Outdated entries are marked in the email with a short language tag
  derived from the language's ISO code, e.g. `[DE]`, `[EN]`, `[FR]`,
  `[IT]` - so editors immediately know which language version needs
  attention.

The following are excluded from the check by default:

- **Hidden pages** - not live for visitors, so they're skipped entirely
  (neither reported nor evaluated). Can be enabled via `checkHiddenPages`.
- **Hidden content elements** - excluded from the `MAX(tstamp)` calculation.
  Also controlled by `checkHiddenPages`.
- **Shortcut, Spacer, Sysfolder, Recycler and other non-content page types** -
  see `excludedDoktypes` in the TypoScript configuration section below.
- Pages using **"Show content from page"** (`content_from_pid` set) - the
  actually relevant page is the one they reference, which is checked in
  its own right anyway. This exclusion is always active and not configurable.

## Configurable fields per task instance

- **Entry page (page UID):** starting point in the page tree. This page
  and all of its subpages (at any depth) are checked.
- **Considered outdated after (days):** the threshold described above.
- **Recipient email address(es):** comma-separated, multiple allowed.
- **Sender email address / name:** optional; falls back to the system's
  default sender address from the `MAIL` configuration if left empty.
- **Email subject:** optional; `{count}` is replaced with the number of
  outdated pages found. Falls back to the TSconfig `email.defaultSubject`
  setting if left empty.

## TypoScript configuration

The extension ships a set of **Page TSconfig** settings that control how the
outdated-pages check behaves. The defaults are applied globally (registered
automatically via `ext_localconf.php`), so the check works out of the box
without any additional configuration. All settings can be overridden per page
tree branch using TYPO3's normal Page TSconfig inheritance.

### Where to put the TSconfig

Open the page record of any page (e.g. the site root) in TYPO3's backend,
switch to the tab **Resources → Page TSconfig** and add your overrides there.
Because TYPO3 merges TSconfig from root to the current page, a setting placed
on the site root applies everywhere beneath it; a more specific override on a
subfolder page wins for that branch.

Alternatively, you can place global overrides in a `.tsconfig` file that is
included from your site package's `ext_localconf.php`:

```php
ExtensionManagementUtility::addPageTSConfig(
    "@import 'EXT:my_site_package/Configuration/page.tsconfig'"
);
```

### Reference

All settings live under the key:

```
module.tx_bw_outdated_pages.settings
```

---

#### `excludedDoktypes`

**Type:** comma-separated list of integers  
**Default:** `3,4,6,7,199,254,255`

Page types (doktypes) that are never checked, regardless of their position in
the tree. The defaults cover page types that carry no editorial content of
their own:

| Doktype | Label |
|---------|-------|
| 3 | External URL |
| 4 | Shortcut (points to another page) |
| 6 | Backend user section |
| 7 | Mount point |
| 199 | Spacer / Divider |
| 254 | Sysfolder |
| 255 | Recycler |

**Example — also exclude a custom doktype 130:**

```
module.tx_bw_outdated_pages.settings {
    excludedDoktypes = 3,4,6,7,130,199,254,255
}
```

**Example — check shortcuts too (remove doktype 4):**

```
module.tx_bw_outdated_pages.settings {
    excludedDoktypes = 3,6,7,199,254,255
}
```

---

#### `excludePagesUnderSysfolders`

**Type:** boolean (`0` / `1`)  
**Default:** `1`

When enabled, pages whose **direct parent** is a Sysfolder (doktype 254) are
excluded from the check. This is useful because Sysfolders are typically used
to organise backend data (news records, form definitions, etc.) rather than
actual website pages, so their children are often irrelevant for an
editorial review cycle.

Set to `0` if you store regular website pages inside Sysfolders and want them
included in the check.

```
module.tx_bw_outdated_pages.settings {
    excludePagesUnderSysfolders = 0
}
```

---

#### `defaultDaysThreshold`

**Type:** integer (days)  
**Default:** `180`

The number of days without a change after which a page is considered outdated.
This setting controls the **default value** shown in the backend module's
"Considered outdated after" input field. Editors can still adjust the value
interactively in the module for an ad-hoc check — this is only the pre-filled
default.

The scheduler task has its own **per-task** days field (configured directly in
the Scheduler module), which always takes precedence over this TSconfig value
for the scheduled email notification.

```
module.tx_bw_outdated_pages.settings {
    defaultDaysThreshold = 365
}
```

---

#### `checkContentElements`

**Type:** boolean (`0` / `1`)  
**Default:** `1`

When enabled (default), the `tstamp` of all non-hidden content elements on a
page is included in the "last changed" calculation. This matters because
TYPO3 does **not** automatically bump `pages.tstamp` when a content element
is edited — without this check, a page whose body text was updated last week
but whose page properties haven't been saved in a year would incorrectly
appear as outdated.

Set to `0` to check only the page record's own `tstamp`. This can be useful
for branches where content elements are managed or imported externally and
their `tstamp` values are not meaningful for an editorial review cycle.

```
module.tx_bw_outdated_pages.settings {
    checkContentElements = 0
}
```

---

#### `maxDepth`

**Type:** integer  
**Default:** `0` (unlimited)

Limits how many levels deep the page tree is traversed below the entry page.
`0` means no limit (the entire subtree is checked).

Useful when a site's top-level navigation should be reviewed regularly, but
the deeper archive pages are intentionally left untouched and should not
generate noise in the report.

**Example — check only the entry page and one level below it:**

```
module.tx_bw_outdated_pages.settings {
    maxDepth = 1
}
```

---

#### `excludePageUids`

**Type:** comma-separated list of integers (page UIDs)  
**Default:** *(empty — nothing extra excluded)*

A list of specific page UIDs that are always skipped, regardless of their
doktype or position in the tree. Only the listed pages themselves are skipped;
their subpages are still traversed and checked normally.

This is the right tool for pages that are intentionally static — legal notice,
privacy policy, a permanent welcome page — and would otherwise cause
recurring false positives in the report.

```
module.tx_bw_outdated_pages.settings {
    excludePageUids = 5,17,42
}
```

> **Note:** `excludePageUids` skips only the listed pages themselves — their
> subpages are still traversed and checked. There is currently no built-in way
> to exclude an entire subtree; to achieve that you would need to list every
> page UID in that branch individually.

---

#### `checkHiddenPages`

**Type:** boolean (`0` / `1`)  
**Default:** `0`

When enabled, hidden (disabled) pages and their content elements are included
in the outdated-pages check. By default they are skipped, since they are not
visible to visitors and flagging them for review is usually noise.

Set to `1` if your editorial workflow keeps draft pages hidden for long periods
and you want to ensure those drafts are still regularly reviewed.

```
module.tx_bw_outdated_pages.settings {
    checkHiddenPages = 1
}
```

---

### Full example

```
module.tx_bw_outdated_pages.settings {
    # Only flag pages older than one year
    defaultDaysThreshold = 365

    # Also check pages inside Sysfolders
    excludePagesUnderSysfolders = 0

    # Only go two levels deep
    maxDepth = 2

    # Homepage and legal pages are intentionally static
    excludePageUids = 1,5,17

    # Do not count content element edits (page record only)
    checkContentElements = 0

    # Also report outdated hidden drafts
    checkHiddenPages = 1
}
```

## Customising templates

### Email template

The notification email is rendered as a Fluid plain-text template. The default
template is located at:

```
EXT:bw_outdated_pages/Resources/Private/Templates/Email/OutdatedPages.txt
```

To replace it, copy the file into your site package, adjust it, and point the
extension to your copy via TSconfig:

```
module.tx_bw_outdated_pages.email {
    templatePath = EXT:my_site_package/Resources/Private/Templates/Email/OutdatedPages.txt
}
```

**Available Fluid variables inside the template:**

| Variable | Type | Content |
|---|---|---|
| `{pages}` | array | Each entry has `uid`, `title`, `languageTag`, `date` (formatted `d.m.Y`) |
| `{daysThreshold}` | int | The configured threshold in days |
| `{count}` | int | Number of outdated pages found |

**Default email subject** (used when the task's own subject field is empty):

```
module.tx_bw_outdated_pages.email {
    defaultSubject = [TYPO3] {count} veraltete Seitenversion(en) gefunden
}
```

`{count}` in the subject is replaced with the actual number before sending.
A subject set directly in the scheduler task always takes precedence over this
TSconfig default.

---

### Backend module templates

The backend module (`Backend/Index.html`) uses TYPO3's standard Fluid template
resolution. Additional template root paths can be added via TSconfig — paths
with a **higher numeric key** take precedence (they are tried first):

```
module.tx_bw_outdated_pages.view {
    templateRootPaths {
        20 = EXT:my_site_package/Resources/Private/Templates/BwOutdatedPages/
    }
    partialRootPaths {
        20 = EXT:my_site_package/Resources/Private/Partials/BwOutdatedPages/
    }
    layoutRootPaths {
        20 = EXT:my_site_package/Resources/Private/Layouts/BwOutdatedPages/
    }
}
```

Place your override file at the same relative path as the original, e.g.
`Backend/Index.html` inside your configured `templateRootPaths.20` folder.

---

### Dashboard widget template

The dashboard widget resolves its template (`Widget/OutdatedPagesList.html`)
using TYPO3's `BackendViewFactory`. To override it, register an additional
extension key in the widget's service definition inside your site package's
`Configuration/Services.yaml`:

```yaml
dashboard.widget.blueways.bw_outdated_pages.outdatedPagesList:
  arguments:
    $options:
      refreshAvailable: true
      additionalExtensionKeys:
        - my-vendor/my-site-package
```

Extension keys added here are appended after the built-in ones, so they have
**higher priority** and can override `Widget/OutdatedPagesList.html`. Place
your override at
`Resources/Private/Templates/Widget/OutdatedPagesList.html` inside your
extension.

> The extension key format must match the Composer package name
> (e.g. `my-vendor/my-site-package`), not the TYPO3 extension key.

## Setting up a task

1. Open the backend module **System > Scheduler**.
2. Add a new task and select the class **"Outdated pages"**.
3. Fill in the four fields described above and choose a frequency
   (e.g. weekly).
4. For each additional department/section, add another task instance
   with its own entry page and recipients.

## Dashboard widget

The extension adds an **"Outdated pages"** widget to the TYPO3
dashboard (System extension **Dashboard**, shipped with TYPO3 by
default). It can be added to any backend user's dashboard via the "+"
button, same as any other widget.

- It aggregates results across **all page tree branches the current
  backend user has access to** (their configured web mounts) — not
  tied to any scheduler task configuration. Admins without explicit
  web mounts see all configured site roots.
- Each entry shows the language tag, page title, page ID and last-changed
  date, and links directly to that page in the Page module - editors can
  jump straight to the outdated page instead of navigating the page tree
  manually.
- The list is capped at 20 entries (oldest first) to keep the widget
  readable; the notification emails remain the complete, authoritative
  list.
- If nothing is outdated, the widget simply confirms that.
- The widget re-runs the same check live (has a refresh button), so it
  can be more current than the last email that went out.
- Each entry has a **"Mark as reviewed"** button - see below.

## Mark as reviewed

Sometimes a page is genuinely fine as-is even though it's old - terms
of service, a historical page, a static info box. Rather than forcing
an edit just to "touch" the page, editors (with edit permission on that
page) can click **"Mark as reviewed"** in the widget or the backend
module.

- This is stored per page **and** language (`tx_bwoutdatedpages_review`),
  so reviewing the German version doesn't affect the English one.
- A review is treated exactly like a content change: it counts toward
  the same `MAX(...)` comparison as `pages.tstamp` and the content
  elements' timestamps. That means it **naturally expires again** once
  the configured threshold has passed since the review - a page isn't
  silenced forever by a single click, it just gets one more "grace
  period" of the same length as the check itself.
- The AJAX endpoint checks that the current backend user actually has
  edit permission on the page before recording anything.

## Backend module

Alongside the dashboard widget, the extension adds a full **"Outdated
pages"** backend module (under the **Web** module group). Unlike the
dashboard widget (which aggregates the pre-configured scheduler task
instances), the module follows the **page tree selection**, just like
the Page module: click a page, and it checks that page plus its
subpages ad-hoc - independent of whether a scheduler task happens to be
configured for that branch.

Available filters (all as simple GET parameters, so they're
bookmarkable/shareable):

- **Considered outdated after (days)** - freely adjustable per view,
  defaults to 180; lets you explore "what would be outdated at a
  stricter/looser threshold" without touching any scheduler task.
- **Language** - narrow down to one language version at a time.
- **Title search** - simple substring match.

Each row has the same **"Mark as reviewed"** action as the dashboard
widget - useful when working through a branch systematically.

## Access control

Both the dashboard widget and the backend module filter results through
`PageAccessChecker`: a page only shows up if the currently logged-in
backend user is both within one of their configured **web mounts** and
has the page-level **"show" permission** on that specific page record
(admins always see everything). This means a scheduler task's entry
point can cover more of the tree than any individual editor is allowed
to see - each editor still only sees what they could also reach via
their own page tree. The "Mark as reviewed" action additionally checks
for **edit** permission (not just "show") before recording anything, and
also verifies the web mount.

## Architecture

The actual "find outdated pages" logic lives in
`Classes/Service/OutdatedPagesFinder.php`, used directly by the
scheduler task (`OutdatedPagesTask`), by the backend module
(page-tree-scoped, ad-hoc), and indirectly by the dashboard widget via
`OutdatedPagesCollector` (which aggregates it across every configured
task instance). This keeps the task class itself minimal, per TYPO3's
own recommendation for scheduler tasks: task objects are serialized
into the database, so the class should change as little as possible
over time; the business logic sits in an ordinary, easily testable
service class instead.

`OutdatedPagesCollector` finds all enabled `OutdatedPagesTask` instances
via `SchedulerTaskRepository::findByUid()` - the same repository TYPO3
Scheduler's own backend module and CLI command use internally to turn
stored task records back into task objects, rather than assuming a
specific database column layout ourselves (which has changed between
TYPO3 patch/minor versions).

## Notes / possible extensions

- The task uses TYPO3's default mailer (`MailMessage`), so the globally
  configured `MAIL` transport settings apply.
- Backend links to the affected pages are deliberately not included in
  the **email** (kept robust against server/routing configuration in
  CLI/cron context) - but they *are* included in the **dashboard
  widget** and the **backend module**, since both always run inside a
  full backend request where building such a link is safe.
- The email subject/body is deliberately hardcoded in German (not
  translated via `locallang.xlf`), since German is the only language
  currently required for these notification emails, and scheduler tasks
  typically run outside a backend user context (CLI/cron) where a
  reliable backend language cannot always be determined anyway.
  Everything the editor sees in the backend (dashboard widget, backend
  module, buttons, filters, scheduler task fields) does use
  `locallang.xlf` and is available in English (default) and German,
  automatically switching with the backend user's own language setting.
- A small badge/icon directly in the page tree itself would be a further
  possible addition, if useful.
