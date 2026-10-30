---
name: AxiomPress Gutenberg native API proof
description: Observed design of the bounded development writing experiment; not the final administration system.
colors:
  text: "#202124"
  shell: "#f6f7f7"
  surface: "#fff"
  border: "#727780"
  link: "#2546cb"
  focus: "#3858e9"
  disabled-text: "#565b66"
  disabled-surface: "#e9ebef"
  selection: "#dce3ff"
  selection-text: "#15285a"
typography:
  headline:
    fontFamily: "system-ui, sans-serif"
    fontSize: "28px"
    lineHeight: 1.2
  body:
    fontFamily: "system-ui, sans-serif"
    fontSize: "15px"
    lineHeight: 1.6
  label:
    fontFamily: "system-ui, sans-serif"
    fontWeight: 600
rounded:
  control: "4px"
spacing:
  control-y: "8px"
  action-gap: "10px"
  control-x: "12px"
  compact: "16px"
  action-offset: "18px"
  standard: "24px"
  spacious: "32px"
components:
  button:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.control}"
    padding: "8px 12px"
  button-disabled:
    backgroundColor: "{colors.disabled-surface}"
    textColor: "{colors.disabled-text}"
  title-input:
    padding: "12px"
    width: "100%"
  writing-canvas:
    backgroundColor: "{colors.surface}"
    padding: "32px"
---

# Design System: AxiomPress Gutenberg native API proof

## Overview

This records the existing code-led Operate experiment, not a new visual identity or an approved final administration design. Its source of truth is `tools/axiompress/experiments/gutenberg-proof.html`, `.css` and `.js`, with dependency/mounting boundaries in `GUTENBERG_NATIVE.md`. The reviewed desktop/mobile captures are `docs/gutenberg-native-proof/desktop.png` and `mobile.png`; screenshots illustrate a published fixture, not every failure state.

The surface extends incumbent system typography and restrained neutral colors. Familiar publishing behavior remains the product goal. This bounded proof supplies real Gutenberg writing components and native persistence; it does not define the complete administration feature scope or complete Phase 4. No approved raster comp, new logo, or shipping raster asset exists for it.

**Key Characteristics:**

- Readable writing surface with explicit draft and publication commands.
- Wrapping controls, visible keyboard focus and text-based live feedback.
- Development-only component proof with preserved native authority boundaries.

## Colors

Neutral shell, white canvas and visible control borders keep attention on content. Blue identifies links and keyboard focus. Disabled controls retain distinct text and background treatments; status meaning is communicated in words rather than a color-only signal. Frontmatter records exact local stylesheet values; upstream Gutenberg styles remain a separate dependency rather than a fully extracted global token system.

## Typography

Use the existing system sans serif for this technical surface. The experiment heading uses the headline role; body, inputs and buttons share the body role, and field labels use the semibold label role. Native heading defaults and Gutenberg block styles supply additional content hierarchy; they have not been promoted into a new AxiomPress type scale. System typography here is a retained development choice, not authorization for a public-facing display identity.

## Layout

The main content box has a maximum content width of 980px, 32px vertical auto-centered margin and 24px padding. The title input spans its container. Actions form a wrapping flex row with the action-gap spacing and action-offset top margin. The writing canvas has a 320px minimum content height; blocks have a 760px maximum width and 24px auto margins.

At a viewport width of 540px or less, main margin becomes zero and main/canvas padding becomes 16px. The 1440px desktop and 390px mobile captures show the title, action rows, status and sign-in link before the canvas; mobile controls wrap rather than forcing a fixed toolbar width. These are observed experiment layouts, not the final writing-canvas/publication-sidebar composition.

## Elevation & Depth

The local proof stylesheet uses flat surfaces without shadows. Shell/canvas contrast and control borders establish separation. Gutenberg's imported component chrome is not a new global elevation vocabulary.

## Shapes

Buttons use gently rounded corners and thin solid borders. The input is a full-width bordered native control. The canvas is rectangular; no decorative card or image treatment is introduced.

## Components

### Commands and title field

Explicit controls add paragraphs/headings, save a draft, publish a saved revision, reload and refresh the session. Draft persistence and publication remain separate. Publish is disabled while dirty, busy, pending, absent or already published; editing is inert while uninitialized, busy or a write outcome is uncertain. Save becomes Retry last write for an unresolved command, retaining its identity and buffer. Disabled styling and native disabled semantics work together.

The title has a visible associated label. Keyboard focus uses a 3px solid focus-color outline with a 3px offset; task completion restores focus to the initiating connected, enabled control. Selection uses the recorded selection colors. No custom local hover or motion treatment is defined; do not infer an animation system from the proof.

### Writing canvas

Real BlockEditorProvider, BlockList, WritingFlow, ObserveTyping and RichText mount only in the disposable harness with `--editor-proof`. Paragraph/heading registrations accept the native versioned bold/italic subset. The proof does not load the full block library/editor application or the privileged inherited PHP administration bootstrap. Unknown documents are rejected before content mounts or editing is enabled, retaining the stored source.

### Recovery feedback

A polite live status region reports readiness, save/publication, stale edits, permission denial, unavailable service and expired sessions. Session recovery tells users to open sign-in in another tab, refresh the session and retry a pending write when applicable. Conflict recovery warns users to copy edits before reloading. Uncertain writes lock editing and instruct retry without tab reload. Reload asks before discarding dirty edits. Buffers remain in memory only; closing the tab can lose unsaved edits.

## Do's and Don'ts

### Do:

- Do preserve familiar publishing behavior while keeping authentication, authorization and persistence in native core APIs.
- Do retain wrapping controls, visible keyboard focus, labelled inputs and actionable live feedback in this proof.
- Do treat the pinned Gutenberg build, mounting instructions and native block contract as dependencies requiring selective review.

### Don't:

- Don't turn this experiment's paragraph/heading subset into an approved exclusion of other publishing workflows.
- Don't describe this surface as final administration, full Gutenberg parity, accessibility certification or Phase 4 completion.
- Don't infer production performance, CSP or deployment readiness from this development proof: its 43 unminified scripts total 12,893,348 bytes and its inline-style allowance is a fixture concession.
- Don't claim operating-system clipboard coverage from synthetic plain-text paste, or claim autosave, image-picker acceptance or complete transitive provenance from this workload.
