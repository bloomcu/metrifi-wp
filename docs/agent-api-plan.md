# MetriFi WP Agent API: Scope of Work

Draft, November 11, 2026. Proposed by Derik Krauss for review by Ryan Harmon.

## Summary

We will extend the MetriFi WP plugin (this repo, v1.3 today) into an agent API, so Claude can do everything in the WordPress CRO skill without a browser, signed in as the actual person asking.

On November 11, 2026 Claude copied the metrifi.com homepage to dev, built a new experiments-led homepage from the theme's blocks, generated art, built a block lab and set up a Nelio A/B test. It did all of that by driving one person's logged-in Chrome, which only works for that person, while their browser is open. It also kept running into wp-admin limits: the 1,000-field cap, block content silently dropped on publish, stale previews.

The target:

- **Per-person identity.** Each team member has their own signing key. The plugin verifies it, acts as that person's own WordPress user, and logs every change under their name. Removing someone means taking one key off a list.
- **No per-site setup.** Installing the plugin through WP Umbrella is the whole setup for a site. No application passwords.
- **Parity.** Every task from November 11 and every workflow in the WordPress CRO skill, Nelio included, works through the API and passes an acceptance test before the browser path is retired.
- **Guardrails stay.** Dev first, production changes only with an explicit permission, and nobody's key can start a Nelio test or edit a Global Block.

The plugin is installed on 28 sites today, most of them dev sites, per WP Umbrella.

## What the plugin does today (v1.3)

- `POST /metrifi/v1/create-page`: application password with `edit_pages`; creates a draft page and saves Content Blocks with ACF's `update_field`. Create only: no read, update, copy, publish or media.
- `GET /metrifi/v1/status`: public "installed and active" check.
- Two ACF filters meant to expose fields in the WordPress REST API. They do not expose the theme's Content Blocks (pages still return them empty).

Its server-side save is the core of this plan. Both endpoints stay for backward compatibility.

## Everything this must cover

There are 30 capabilities: everything Claude did on November 11 plus everything the WordPress CRO skill teaches. Each one maps to an endpoint and an acceptance test. Nothing moves to the API until its test passes.

| ID | Capability | Where it came from | How the API covers it | Test |
| --- | --- | --- | --- | --- |
| C1 | Read a page's blocks, in order, with every field | Copying the metrifi.com homepage | `get-page` | T1 |
| C2 | Copy a page to a new draft on the same site | Skill: replicate a page | `copy-page` | T2 |
| C3 | Copy a page from production to dev, images included | Copying production's homepage to dev | `get-page` on the source + `import-page` on the target, images pulled by URL | T3 |
| C4 | Create a new draft page from blocks | The experiments-led homepage (19 sections) | `save-page` with no page ID | T4 |
| C5 | Change blocks on a draft (layout swap, new band, margin) | Three review fixes | `save-page` | T5 |
| C6 | Change blocks on a published page without unpublishing it | Skill: `updatePublished` | `save-page` with `allowPublished` | T6 |
| C7 | Publish, unpublish, set the slug | Publishing the variant and the block lab | `set-status` | T7 |
| C8 | Trash a page (never permanently delete) | Cleaning up a test page | `set-status: trash` | T8 |
| C9 | Save very large pages (over 1,000 fields) | The block lab could not be published from wp-admin | Server-side save, no form | T9 |
| C10 | List every block layout, field and option | Skill: block catalog (41 blocks) | `get-block-schema` | T10 |
| C11 | Build or refresh the block lab page | The block lab (about 50 variants) | `build-block-lab` | T11 |
| C12 | Reject bad block data before saving (unknown field, empty `data_source`) | Blank blocks and lost rows on November 11 | Validation in `save-page` | T12 |
| C13 | Find pages, the front page, slugs, IDs | Every task | `find-pages`, `site-info` | T13 |
| C14 | Upload images and set alt text | Hero, loop diagram, icons | `upload-media` | T14 |
| C15 | Generate illustrations and icons | Skill: image scripts | Unchanged, runs on Claude's side | T15 |
| C16 | A shareable preview of a draft, no login | Visual QA and Nelio previews | `preview-link` | T16 |
| C17 | Visual QA at desktop and 390px | Skill: visual QA (required) | Headless screenshots of `preview-link` URLs | T17 |
| C18 | Check links and calls to action on a page | Skill: visual QA | `get-page` returns rendered links; client checks status codes | T18 |
| C19 | Read Global Blocks, never edit them | Skill rule | `get-page` resolves Global Blocks read-only | T19 |
| C20 | Look up proven patterns and past tests | Skill: design step | Unchanged, MetriFi MCP | n/a |
| N1 | Create a Nelio page test with an existing page as variant, served at the control's URL | The homepage test | `nelio-save-test` | T20 |
| N2 | Name, description, hypothesis, variant name | The homepage test | `nelio-save-test` | T20 |
| N3 | Goals: page view, click external link, JavaScript snippet (Calendly, Typeform) | The four goals | `nelio-save-test` | T21 |
| N4 | Read a test: status Ready, previews return 200 | Hand-off check | `nelio-get-test` | T22 |
| N5 | Read Nelio's site-wide settings | Hand-off check | `nelio-settings` (read only) | T23 |
| N6 | Refuse to start, stop, pause or apply a test | Ground rule | Not exposed; proxy blocks those routes | T24 |
| N7 | Warn when another test already runs on the same page | Hand-off check | `nelio-get-test` scans running tests | T25 |
| N8 | After a person starts the test, read results to confirm goals register | Post-launch check | `nelio-results` (read only) | T26 |
| X1 | Know which sites have the plugin and which version | WP Umbrella inventory | WP Umbrella + `site-info` | T27 |
| X2 | Every change attributed to a real person, with a way to undo it | Attribution requirement | Per-person keys, own WP user, revisions, audit log | T28 to T35 |

## Identity and security

Every request is signed with one person's own key. The plugin verifies the signature, then acts as that person's own WordPress user, so WordPress, Stream, Nelio and the plugin's log all record who did it.

**Keys and the team registry**

1. Each team member generates an Ed25519 key pair with a skill script. The private key goes into their own password manager and is never shared.
2. Their public key goes into a team registry: name, email, role, and which environments they may change (dev, production).
3. The registry is a JSON file signed by a MetriFi root key held by the two owners. The plugin ships with only the root's public key.
4. Every site fetches the registry from one fixed URL every 15 minutes, checks the root signature, and refuses an older version than the one it has (no rollback). If the fetch fails, it keeps the last good copy.
5. Removing a person = delete their entry, re-sign, publish. Every site drops them within 15 minutes, with no per-site work.

**Each request**

- Headers carry the key ID, a timestamp, a one-time nonce and a signature over the method, path, query, a hash of the body, the timestamp, the nonce and the site's host name.
- The plugin rejects a timestamp more than 5 minutes off, a nonce it has seen in the last 10 minutes (replays), a signature made for another site, an unknown or revoked key, and any body that does not match its hash.

**Acting as the person**

- The first verified request from a person creates their WordPress user on that site (for example `metrifi-<name>`, Editor role unless the registry says otherwise; never Administrator by default). Later requests reuse it.
- Every change runs as that user: revisions, the Stream activity log, Nelio and the plugin's audit log all show the real person.
- When a key is revoked, the plugin removes that user's role on the next registry refresh. Their past changes keep their name.

**Guardrails built into the plugin**

- Environment: dev hosts (our dev domains) are dev; everything else is production. A write on production needs the person's production permission AND an explicit `confirmProduction` flag on the request.
- Never exposed: users, plugins, themes, site settings, permanent delete, Global Block writes, raw database access, and Nelio's start, stop, pause and apply.
- Audit log: a table of time, person, action, page, revision before and after, and result, readable in wp-admin and through `get-activity`. Any change can be rolled back from its revision.
- Kill switch: an "Agent API enabled" setting per site, off by default on production until the client agrees. WP Umbrella can also deactivate the plugin.
- Rate limit per person, to stop a runaway loop.

**Root key safety:** the root key signs only the registry, never requests. It is kept offline in a vault only the two owners can open. Rotating it requires a plugin release, so the procedure is written down before launch.

## Plugin endpoints

About a dozen endpoints under `/wp-json/metrifi/v2/` cover pages, blocks, media and previews. Saves go through ACF's own `update_field` inside WordPress, which removes every wp-admin problem from November 11 by design. The existing `metrifi/v1/create-page` and `status` stay for backward compatibility.

| Endpoint | Does | Rules |
| --- | --- | --- |
| `GET site-info` | Plugin version, environment (dev or production), front page, theme, the Content Blocks field key, ACF and Nelio versions | Read only |
| `GET find-pages` | Search pages by title, slug or status; returns ID, title, status, link, modified date | Read only |
| `GET get-page` | A page's blocks in order with every field, image IDs plus their URLs and alt text, Global Blocks resolved read-only, the rendered links on the page | Read only |
| `GET get-block-schema` | Every layout with its fields, types, options, defaults and repeater nesting, read live from ACF on that site | Read only; replaces the hand-written catalog |
| `POST save-page` | Create a draft (no ID) or replace a page's blocks (ID), plus title, slug and excerpt | Validates every row against the schema first and rejects unknown fields; fills `data_source` with `default`; always writes a revision; a published page needs `allowPublished` and stays published; production needs `confirmProduction` |
| `POST copy-page` | Duplicate a page with all blocks into a new draft | Same site only |
| `POST import-page` | Create a draft from another site's `get-page` output | Pulls each image by URL into the media library, reusing an existing copy when the file matches; reports anything it could not bring |
| `POST set-status` | Draft, publish, unpublish, trash | Never a permanent delete; production needs `confirmProduction` |
| `POST upload-media` | Upload a file or pull one by URL; set title and alt text | Images and PDFs only, size limit |
| `POST preview-link` | A signed link that renders a draft for 30 minutes without a login | No indexing; used for screenshots and review |
| `POST build-block-lab` | Creates or refreshes a draft "Block lab" page that shows every layout and variant with sample copy, labeled | Draft only; saves server-side, so its size is not limited |
| `GET get-activity` | The audit log, filterable by person, page and date | Read only |

Each endpoint is also registered as a WordPress Ability, so the same actions are available through WordPress's built-in MCP layer if we ever want it. The signed API above is the primary path.

**Not covered by the plugin on purpose:** image generation (runs on Claude's side), proven patterns (MetriFi MCP), plugin inventory and updates across sites (WP Umbrella).

## Nelio A/B testing

The plugin passes Nelio calls through on the person's behalf, so a Nelio test can be fully set up through the API and left at "Ready" for a person to start. On November 11 we confirmed Nelio's own API (`/nab/v1/experiment/<id>`) reads and saves a test with an application password, with nothing changed. That test used an Administrator account.

The WordPress CRO skill already teaches the full Nelio setup (`references/nelio-ab-tests.md` plus `scripts/nelio-goal-snippets.js`). This project keeps every step and moves it from Nelio's editor screen to the API.

**Endpoints** (the plugin calls Nelio's API inside WordPress as the person's own user, so Nelio records them):

| Endpoint | Does | Rules |
| --- | --- | --- |
| `GET nelio-list-tests` | Tests on the site with status, type and tested page | Read only |
| `GET nelio-get-test` | One test: variants, goals, actions, description, status, and whether each variant's preview returns 200 | Read only; also flags another running test on the same page |
| `POST nelio-save-test` | Create or update a page test: control page, "test against an existing page", "use control URL in variants", variant page, names, description, goals and actions | Only the fields listed here; refuses anything that changes the test's status |
| `GET nelio-settings` | Tested visitors, goal tracking scope, the `nab` URL argument settings, GA4 setting | Read only; never written |
| `GET nelio-results` | Results per goal and variant, once a person has started the test | Read only |

Start, stop, pause, resume and apply are never exposed, and the plugin blocks those Nelio routes for agent requests even if someone calls Nelio directly with an agent key.

**The setup the API must reproduce** (the November 11 homepage test as the reference case):

1. Variant B is a real, published page. A draft variant returns 404 in Nelio's preview.
2. Control = the current page, with "Test against an already existing page" and "Use control URL in variants" on, so B shows at the homepage URL.
3. Goals match both pages' real calls to action, primary first: any lead (Calendly booking snippet + Typeform submit snippet + click on the sign-up link), booked a call or sent the form, started sign-up, visited a contact page.
4. Snippets come from the skill's library and check the sender's origin; the HubSpot snippet is still untested.
5. Description carries the hypothesis, the primary goal and the GA4 note (A = `nab=0`, B = `nab=1`).
6. Hand-off check: status Ready, both previews 200, settings suit the UXR process, no other test on the page.
7. After a person starts it: book one test call and send one test form, then confirm both goals register through `nelio-results`.

**To verify early:** whether an Editor-role user can create and edit Nelio tests. If not, the registry gives testers a role with Nelio's capability, never Administrator.

## Claude side

The WordPress CRO skill gets a small signed client in place of its Chrome helpers. Every workflow and rule in the skill stays the same; only how Claude reaches the site changes.

- **Key setup script:** generates a person's key pair, stores the private key in their password manager, and prints the public key to add to the registry. One time per person.
- **`metrifi-wp` client:** a command-line tool in the skill's `scripts/`. It reads the person's key once per session (one prompt), signs each request, and has one command per endpoint (`get-page`, `save-page`, `nelio-save-test`, and so on). Sites are named by host, and the site list comes from WP Umbrella.
- **Local MCP server:** the same client wrapped as an MCP server, so Claude Code and Claude desktop call it as tools. A hosted connector for claude.ai is a separate decision.
- **Page building:** the build script format stays (`P.add('feature', {...})` in `example-homepage-build.js`) and now posts to `save-page`. The client checks fields against `get-block-schema` before sending.
- **Visual QA without a login:** a headless browser script (Playwright) opens `preview-link` URLs at 1440 px and 390 px wide, scrolls to load lazy images, screenshots each section, and checks horizontal overflow and link status codes. Claude reviews the screenshots before calling anything done.
- **Images:** generation and the image tools are unchanged; uploads go through `upload-media`.
- **References rewritten:**
    - `saving-and-gotchas.md` shrinks to a short history.
    - `block-catalog.md` is generated from `get-block-schema`.
    - `nelio-ab-tests.md` switches from editor clicks to API calls, keeping every rule.
    - `workflows.md` and `visual-qa.md` point at the client.
- **The Chrome path** stays in the skill as a documented fallback until every acceptance test passes on the API, then it is removed.

## Rollout with WP Umbrella

The new version ships as a normal plugin update through WP Umbrella, dev sites first, with production turned on one client at a time.

1. **Release:** build and tag v2.0 in this repo, with a built-in update checker so WordPress sees new versions.
2. **Clean up first:** remove one site's duplicate install (two copies, one inactive), and bring the two sites still on 1.2 up to date.
3. **Pilot:** our MetriFi dev site and the base dev site. Run the full test plan there.
4. **Dev sites:** update the other client dev sites through WP Umbrella and confirm each reports v2.0 in `site-info`.
5. **Production, per client:** metrifi.com first, then each client after their OK, with the agent API switched on by its setting.
6. **New sites:** add the plugin to the base dev site so every new build starts with it.

## Test plan

The project is done when all 35 tests pass on the dev sites, and when a full rerun of the November 11 session (copy, build, fix, block lab, Nelio test) completes with Chrome closed. Plugin logic gets automated PHP tests; the rest runs as a scripted end-to-end suite against the two pilot dev sites. Production is only read (T1) until rollout.

**Capabilities**

| Test | What we run | Passes when |
| --- | --- | --- |
| T1 | `get-page` on the metrifi.com homepage (read only) | Blocks come back in the same order as the 18 rendered sections |
| T2 | `copy-page` on the dev homepage | The copy renders the same 18 sections and headings as the source |
| T3 | `import-page` from metrifi.com to dev | Same sections; every image present, none broken; images already on dev are reused, not duplicated |
| T4 | Build the experiments-led homepage from `example-homepage-build.js` | A new draft renders the same 19 sections as "Homepage (experiments variant)" |
| T5 | Apply the three fixes: Step 3 to `feature_v4`, the loop band, `no_margin` on the last block | Screenshots show the art uncropped, the purple band and 0 px between the CTA and the footer |
| T6 | Change blocks on a published page | It stays published throughout; a logged-out visitor sees the new content; a revision exists |
| T7 | Publish, unpublish, change slug | Status and URL change; content unchanged |
| T8 | Trash a page | It is in the trash and restorable; no call can delete permanently |
| T9 | Save and publish a 51-block page (about 1,460 fields) | Saves in one call; nothing dropped; no nonce error |
| T10 | `get-block-schema` | All 41 layouts, with fields and options matching today's catalog |
| T11 | `build-block-lab` | A draft with every layout and variant renders; each section screenshot is labeled |
| T12 | Save a row with an unknown field, and one with no `data_source` | The first is rejected naming the field, with nothing saved; the second saves as `default` and renders |
| T13 | `site-info`, `find-pages` on dev | Front page found, version 2.0, environment dev |
| T14 | Upload a webp and a png with alt text, use one in a block | Both in the media library with alt text; the block shows the image |
| T15 | Generate one illustration and one icon with the skill's scripts | Files created, then uploaded through T14 |
| T16 | `preview-link` for a draft | Renders logged out for 30 minutes, then refuses; marked no-index |
| T17 | Visual QA script on the T4 page | A screenshot per section at 1440 px and 390 px; no horizontal overflow |
| T18 | Link check on the T4 page | Every link and CTA returns 200 |
| T19 | Read a page with a Global Block; try to write the Global Block | Read shows it; the write is refused |
| T20 | Recreate the November 11 Nelio test on dev with `nelio-save-test` | Same variants, settings, four goals, actions and description as the original test |
| T21 | A person starts a dev-only test; send Calendly and Typeform completion messages from the real domains and from a lookalike domain | The real ones register as conversions; the lookalike does not |
| T22 | `nelio-get-test` on a ready test, then with a draft variant | Ready with both previews 200; then the draft variant is flagged as a 404 |
| T23 | `nelio-settings` | Returns the settings; no write path exists |
| T24 | Start, stop, pause, apply with an agent key, through the plugin and directly against Nelio's routes | All refused |
| T25 | Two tests on the same page | The second is flagged |
| T26 | `nelio-results` after T21 | Conversions per goal and variant are readable |
| T27 | WP Umbrella plugin list vs `site-info` on every dev site | Versions match |

**Identity and security**

| Test | What we run | Passes when |
| --- | --- | --- |
| T28 | A valid request from a test person | Runs as their own WordPress user; the revision, Stream, Nelio and the audit log all show them |
| T29 | Change one byte of the body after signing | Refused; nothing changed |
| T30 | A timestamp 6 minutes old | Refused |
| T31 | Replay a request | The second copy is refused |
| T32 | Send a request signed for site A to site B | Refused |
| T33 | Revoke the test person in the registry | Refused within 15 minutes; their user loses its role; past changes keep their name |
| T34 | Production writes on a dev site switched to production mode: no permission, no flag, then both | Refused, refused, allowed |
| T35 | Forbidden actions (users, plugins, settings, permanent delete), kill switch off, an older or tampered registry | All refused; kill switch blocks every agent request; the site keeps its last good registry |

## Phases and effort

Roughly 20 to 28 working days for one developer, plus the time clients take to approve production. These are first estimates, to be firmed up after Phase 0.

| Phase | Work | Rough effort |
| --- | --- | --- |
| 0. Decisions and spikes | Editor role and Nelio, registry hosting, duplicate-install cleanup | 2 to 3 days |
| 1. Identity and guardrails | Signed registry, request signing, per-person users, audit log, kill switch (T28 to T35) | 4 to 6 days |
| **Gate** | **Every security test passes before any write endpoint ships** | |
| 2. Pages, blocks and media | Get, save, copy, import, status, schema, block lab, media, preview links (T1 to T19) | 5 to 7 days |
| 3. Nelio | List, get, save, settings, results; start and stop never exposed (T20 to T26) | 3 to 4 days |
| 4. Claude client and skill rewrite | Signed client, local MCP server, headless visual QA, rewritten references | 4 to 5 days |
| **Gate** | **The November 11 session reruns end to end with Chrome closed** | |
| 5. Rollout | Dev sites through WP Umbrella, then production one client at a time, each with its OK (T27) | 2 to 3 days, plus client OKs |

**Deliverables:** MetriFi WP v2.0 with its PHP tests; the signed team registry and the root-key procedure; the `metrifi-wp` client, local MCP server and visual QA script; the rewritten WordPress CRO skill; the passing test report for T1 to T35.

## Decisions needed and out of scope

**Decisions before Phase 1**

- [ ] Ryan: OK to extend this plugin this way, and who builds it?
- [ ] Where the signed team registry is published (a release file in this repo, or a static URL we control).
- [ ] Default role per person, and who gets production permission.
- [ ] Whether an Editor-role user can create and edit Nelio tests, or testers need Nelio's own capability.
- [ ] How we ask clients to switch the agent API on for their production site.
- [ ] Whether a hosted connector for claude.ai is needed later, and where it would live (not the MetriFi platform).
- [ ] Whether this repo should become private before the key system lands (it is public today).

**Out of scope**

- Starting, stopping, pausing or applying a Nelio test. A person always does that.
- Monitoring and documenting tests: GA4 funnels, the AB test calculator, case studies and Monday phases stay with the UXR skill.
- MetriFi Site Builder (Astro) sites and the MetriFi platform.
- Theme changes to bloomcu-base, and editing Global Blocks.
- Server settings such as `max_input_vars`. The API makes them irrelevant for agent saves; wp-admin keeps its limit for very large pages.
