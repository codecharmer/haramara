---
name: Haramara
description: A hidden candlelit specialty-coffee bar in Cuernavaca — matte warm blacks, brass light, and the café's actual day as the design's spine.
colors:
  carbon: "#131110"
  alba: "#16191B"
  char: "#1D1916"
  espresso: "#241D17"
  ocaso: "#221A11"
  noche: "#0D0C0A"
  walnut: "#433326"
  smoke: "#A29888"
  limestone: "#D6CCBD"
  bone: "#EFE8DC"
  clay: "#C98F6B"
  olive: "#9B9B79"
  brass: "#C8A566"
  brass-soft: "#E0C48D"
typography:
  display:
    fontFamily: "Italiana, \"Times New Roman\", Didot, serif"
    fontSize: "clamp(3.25rem, fluid, 6rem)"
    fontWeight: 500
    lineHeight: 1.06
    letterSpacing: "0.015em"
  headline:
    fontFamily: "Italiana, \"Times New Roman\", Didot, serif"
    fontSize: "clamp(2.5rem, fluid, 3.5rem)"
    fontWeight: 500
    lineHeight: 1.06
    letterSpacing: "0.015em"
  title:
    fontFamily: "Italiana, \"Times New Roman\", Didot, serif"
    fontSize: "clamp(2rem, fluid, 2.5rem)"
    fontWeight: 500
    lineHeight: 1.06
    letterSpacing: "0.015em"
  body:
    fontFamily: "Petrona, Georgia, \"Times New Roman\", serif"
    fontSize: "clamp(1.5rem, fluid, 1.59375rem)"
    fontWeight: 380
    lineHeight: 1.65
    letterSpacing: "0.005em"
  lede:
    fontFamily: "Petrona, Georgia, \"Times New Roman\", serif"
    fontSize: "clamp(1.1875rem, fluid, 1.3125rem)"
    fontWeight: 340
    lineHeight: 1.65
    letterSpacing: "0.005em"
  label:
    fontFamily: "Petrona, Georgia, \"Times New Roman\", serif"
    fontSize: "clamp(0.8125rem, fluid, 0.875rem)"
    fontWeight: 500
    letterSpacing: "0.18em"
rounded:
  sm: "0px"
  md: "0px"
  lg: "0px"
  pill: "999px"
spacing:
  xxxs: "0.25rem"
  xxs: "0.5rem"
  xs: "0.75rem"
  sm: "1rem"
  md: "1.5rem"
  lg: "2.5rem"
  xl: "4rem"
  xxl: "6.5rem"
  xxxl: "10rem"
components:
  button-ghost:
    backgroundColor: "transparent"
    textColor: "{colors.brass}"
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    padding: "0.95rem 1.9rem"
  button-ghost-hover:
    backgroundColor: "{colors.brass}"
    textColor: "{colors.noche}"
  button-solid:
    backgroundColor: "{colors.brass}"
    textColor: "{colors.noche}"
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    padding: "0.95rem 1.9rem"
  button-solid-hover:
    backgroundColor: "{colors.brass-soft}"
    textColor: "{colors.noche}"
  button-link-underline:
    backgroundColor: "transparent"
    textColor: "{colors.limestone}"
    typography: "{typography.label}"
    padding: "0.2rem 0"
  button-link-underline-hover:
    textColor: "{colors.brass-soft}"
  input-field:
    backgroundColor: "{colors.char}"
    textColor: "{colors.bone}"
    rounded: "{rounded.sm}"
    padding: "0.85rem 1rem"
---

# Design System: Haramara

## Overview

**Creative North Star: "El Día de Haramara" (The Café's Actual Day)**

Haramara's visual world is a hidden candlelit bar rendered in matte warm blacks. Five near-black grounds — carbon, alba, char, espresso, ocaso, settling into noche — carry warm off-white text (limestone, bone) and one metallic voice: brass, which appears only where light would fall. The homepage is not a marketing page; it is the café's actual working day, 08:00–20:00, told as six real hours with hairline "station" rules marking each one. Structure is drawn, never boxed: 1px hairlines, dotted menu leaders, a single brass plumb line — no cards, no fills, no rounded containers.

The material character comes from the café itself: Shou Sugi Ban charred wood, stone, brass, linen, candlelight. Photography is real, cinematic, and low-light — shot at the café, never stock, never generated. A fixed candle-glow veil (`--hm-warmth`) warms the page as the visitor scrolls through the day; it is deliberately faint (0.2 alpha ceiling, eased to the second half of the scroll) and disappears entirely under reduced motion. Confirmed anti-references: trendy coffee shop, startup; never bright saturated colors.

**Key Characteristics:**
- Five matte warm blacks as section grounds, sequenced like daylight fading; never pure `#000`.
- Brass as light, not decoration: hour numerals, hairlines, focus rings, the candle veil, the one action.
- Italiana display (one weight) over Petrona text (variable weight); hierarchy by size, never by boldness.
- Square corners, hairline structure, dotted leaders — no boxed cards anywhere.
- One page-wide motion grammar: rise-and-unblur reveals, light-only cover fades, slow scale-settle imagery.
- WCAG AA on dark grounds is a standing requirement, not an aspiration.

## Colors

An almost-monochrome ladder of warm blacks under warm off-whites, with brass as the single luminous accent and clay/olive as quiet material voices.

### Primary
- **Latón / Brass** (#C8A566): the flame. Hour numerals on the timeline, the plumb line, focus outlines, text selection, the candle veil, ghost-button strokes and the solid commerce action, the "Funky" brew tier, current pagination. It marks where attention and light land.
- **Latón claro / Soft Brass** (#E0C48D): brass under a warmer flame — hover and focus color for links, nav items, and underline buttons; the solid button's hover fill; the active button state.

### Secondary
- **Barro / Clay** (#C98F6B): fired ceramic. The "Groove" brew tier and commerce accents (sale badge fill, error notices). Warm, earthy, sparing.
- **Oliva / Olive** (#9B9B79): dried herb. Success and confirmation states in commerce chrome only. The quietest accent in the room.

### Neutral
- **Noche** (#0D0C0A): the deepest ground — 20:00, the closing section, footer, mobile menu overlay, scrolled-header glass tint, and the text color on any brass fill.
- **Carbón** (#131110): the page's base ground (body background) and the midday sections.
- **Alba** (#16191B): the coolest black, tinged blue-grey — morning light, top of the `alba-fade` gradient.
- **Madera quemada / Char** (#1D1916): charred wood — the 09:30 oven section and every form-field well.
- **Espresso** (#241D17): pulled-shot brown-black for mid-afternoon grounds.
- **Ocaso** (#221A11): the golden-hour black — 18:30's ground, top of `ocaso-fade`.
- **Nogal / Walnut** (#433326): burnt walnut for separators and the quote's left border — the darkest line that still reads as material rather than light.
- **Humo / Smoke** (#A29888): secondary text — station labels, captions, descriptions, notes, meta, placeholders, footer links. 6.9:1 on carbon.
- **Caliza / Limestone** (#D6CCBD): the body text color and default link color; ladder tier names.
- **Hueso / Bone** (#EFE8DC): headings, item names, prices in the ladder, site title — the brightest voice, reserved for what must be read first.

### Named Rules
**The Brass Is Light Rule.** Brass appears only where light would fall: hour numerals, hairlines, focus rings, the veil, and the single decisive action per screen. Text on a brass fill is always noche (#0D0C0A) — never bone; bone-on-brass fails AA.

**The Warm Black Ladder Rule.** Every section sits on one of the named grounds (alba → carbon → char/espresso → ocaso → noche), sequenced to the hour of day it represents. No pure black, no cool greys, no light-mode surfaces.

**The Hairline Rule.** Structural lines are 1px of bone at low alpha — `rgba(239,232,220,0.14)` (`--wp--custom--hairline`) for structure, `0.26` (`hairlineStrong`) for interactive underlines. Dotted leaders use smoke at 0.45.

## Typography

**Display Font:** Italiana (with Times New Roman, Didot fallback) — self-hosted, single weight (400).
**Body Font:** Petrona (with Georgia fallback) — self-hosted variable font, weights 100–900, roman + italic.

**Character:** A high-contrast engraver's display face over a sturdy Latin-American serif. Italiana gives headings the air of an etched sign above a doorway; Petrona at light weights (340–460) keeps long text warm and unhurried. There is no sans-serif anywhere in the system.

### Hierarchy
- **Display** (400, fluid 3.25–6rem, 1.06): the hero headline only ("Café. Fuego. Ritual."), max-width 12ch, balanced wrapping. On short viewports (≤900px height) the display token itself is rescoped to the 3XL size so the full opening stack stays inside the first viewport.
- **Headline / h2** (400, fluid 2.5–3.5rem, 1.06): section headings; also the brew-ladder tier names (uppercase, 0.06em tracking).
- **Title / h3** (400, fluid 2–2.5rem, 1.06): sub-sections; also event dates and the voice size.
- **Body** (380, fluid 1–1.0625rem, 1.65): all prose, letter-spaced 0.005em; long-form measure capped at 64ch (`--wp--custom--measure`). Ledes use the md size (1.1875–1.3125rem) at weight 340, max 44–46ch.
- **Label** (500, fluid 0.8125–0.875rem, 0.18em, UPPERCASE): buttons and underline links. Related label voices: nav links 0.14em, station labels 0.22em (smoke), hero hours line 0.2em, site title 0.32em.

### Named Rules
**The Size, Not Weight Rule.** Italiana exists at one weight; hierarchy is expressed by scale and color, never by bolding. In Petrona, weight moves only within 340–500 (340 ledes/quotes, 380 body, 460 item names, 500 labels).

**The Tabular Price Rule.** Every price is set in `font-variant-numeric: tabular-nums` (carta rows in limestone, ladder prices in bone) so columns of pesos align like a printed menu.

## Layout

The spatial model is a vertical timeline, not a stack of cards. Content is constrained to 700px (prose) and 1240px (wide); the homepage's six "day" sections are full-bleed grounds with generous vertical breath: 2XL padding (6.5rem) on mobile, 3XL (10rem) from 782px up. Root block gap is zeroed on the front page — every section carries its own breathing room. Root side padding is M (1.5rem).

Each hour opens with an **hm-station** row: hairline — brass hour numeral (Italiana, lg) — smoke uppercase label — hairline, flexed across the full width. Stations sit at `z-index: 3` above everything, including lifted media; they are information-carrying structure (real hours), never decorative kickers.

The hero fills `100svh`, content flex-seated to the bottom-left with clamped padding (`clamp(1.1rem, 4vw, 4rem)` sides). The opening stack — headline, lede, actions, hours line — must fit the first viewport at every size; below 900px viewport height the display token steps down and the lede tightens to 42ch. A 1px brass plumb line drops from the hero's bottom center (`clamp(3rem, 9vh, 6rem)` tall) toward the 09:30 station.

Editorial sections use the **hm-overlap** grammar instead of cards: two-column groups where one image lifts (−2.5rem top margin) or drops (−6.5rem bottom margin, `z-index: 2`) across the section seam at ≥782px. Portrait photography inside overlap columns is art-directed to a 4:5 crop with `object-fit: cover` (the ocaso section shifts `object-position` to 50% 22% to keep the bottle and celosía in frame).

The spacing scale runs 0.25rem → 10rem in nine steps (3XS–3XL); rows and list structures use hairline borders plus M/L padding rather than gap-heavy grids. Breakpoints observed: 599px (station gap tightens), 781/782px (header CTA hides; ladder, overlap, and day padding expand), 900px height (hero display rescope).

### Named Rules
**The Station Rule.** Hour stations are data, not decoration: real opening hours, `z-index: 3`, and nothing — not even lifted overlap media — may cover them.

**The First Viewport Contract.** Headline, lede, one primary action, and the hours line all render inside the initial viewport, complete, at every screen size. Short viewports trade a step of display scale, never a member of the stack.

## Elevation & Depth

The system is flat and drawn. Depth comes from the warm-black ground ladder, hairlines, and light — not from floating surfaces. There are exactly three shadow tokens, and none of them decorates a container:

### Shadow Vocabulary
- **Abismo** (`box-shadow: 0 34px 70px -30px rgba(0,0,0,0.72)`): the cave's darkness under photography — framed images and the brand seal only.
- **Filete hueso** (`box-shadow: inset 0 0 0 1px rgba(239,232,220,0.14)`): an inset bone hairline that draws an edge without a border — the framed-image inner line and the `is-style-hairline` group (which adds L padding).
- **Brasa** (`box-shadow: 0 18px 60px -24px rgba(200,165,102,0.28)`): a brass ember-glow lift. Defined in theme.json as part of the token vocabulary; not yet applied by any built surface — reserved for a future candle-lit accent, not free for casual use.

Atmospheric depth is light: the fixed candle veil (`body::after`, radial brass glow from the bottom edge, 0.2 alpha ceiling) whose opacity is `--hm-warmth` — document scroll progress raised to the 1.5 power, so warmth belongs to the second half of the day. The scrim gradients (`scrim-foto`, `scrim-lateral`) seat text on photography; `vela` is the radial candlelight band behind closing CTAs; `alba-fade` and `ocaso-fade` blend one ground into the next.

### Named Rules
**The Flat World Rule.** No container ever casts a shadow. Shadows exist only beneath photography (abismo) and as drawn hairline edges (filete). Depth between sections is a change of ground, not an elevation change.

**The Candle Ceiling Rule.** The daylight veil is deliberately subtle: 0.2 alpha at full warmth, eased by `progress^1.5`, removed entirely under `prefers-reduced-motion`. Do not "fix" its faintness.

## Shapes

Square. Every radius token is 0px — buttons, inputs, images, focus outlines (`border-radius: 0` is set explicitly on focus rings). Structure is drawn with lines: 1px hairline rules and borders, 1px dotted menu leaders, the 1px brass plumb line, walnut separators. There are two sanctioned curves: the circular brand seal (`hm-sello`, the gold flame logo raster clipped to 50% with a brass ring at 0.35 alpha over abismo) and the `pill` token (999px), held in the token set and used only by commerce chrome. No other rounding, no clipping tricks, no diagonal geometry.

**The Square Corner Rule.** If it has corners, they are square. The only circle in the world is the seal — the hand-drawn gold flame in its brass ring.

## Components

### Buttons
Three voices, all uppercase Petrona 500 at label size with 0.18em tracking, all square.
- **Ghost (default):** transparent fill, 1px brass border, brass text, padding 0.95rem 1.9rem. Hover/focus inverts to brass fill with noche text; active uses soft brass. This is the default `wp:button` — the hero's "Ordenar para recoger" is one.
- **Solid brass** (`is-style-solid`): brass fill, noche text; hover moves to soft brass. Reserved for the single decisive action on commerce screens.
- **Link-underline** (`is-style-link-underline` / `.hm-enlace`): no box at all — limestone text over a 1px strong-hairline underline (inset box-shadow); hover turns text and underline soft brass. Transitions run at the fast duration (200ms).

### Header
One component, three states. Transparent (`hm-header--transparent`): fixed, a top-down noche fade (0.55 → 0) over the hero. Scrolled (`.is-scrolled`, added past 8px): fixed noche glass at 0.88 with 14px backdrop blur and a bottom hairline. Solid (`hm-header--solid`): sticky with bottom hairline for inner pages. Nav links are small-size uppercase limestone (0.14em) hovering to soft brass; the mobile menu opens as a full noche overlay where links become Italiana at XL, mixed case. The header CTA is a compact ghost button (0.55rem 1.3rem, 0.75rem text) hidden below 782px.

### Timeline Station (signature)
`hm-station`: full-width flex row — hairline, brass Italiana hour (lg, 0.08em), smoke uppercase label (sm, 0.22em), hairline — with XL bottom margin, `z-index: 3`. Opens every homepage hour: 09:30 El horno, 12:00 La barra de filtrados, 16:00 La cueva, 18:30 (ocaso), 20:00 Cerramos.

### Carta Row (signature)
`hm-carta__row`: flex baseline row — bone item name (weight 460), a flexing 1px dotted smoke leader (nudged up 0.34em to sit on the text baseline, min-width 2rem), tabular-nums limestone price that never wraps. Notes are small italic smoke, max 52ch. Rows carry 0.7rem vertical padding, no borders, no zebra.

### Brew Ladder (signature)
`hm-ladder`: hairline-ruled tiers (top border plus per-tier bottom border), each a baseline grid — single column on mobile; `minmax(13rem,1fr) 2fr auto` at ≥782px. Tier names are Italiana at 2XL, uppercase: Chill in limestone, Groove in clay, Funky in brass — the price of the cup climbs with the color temperature. Descriptions are smoke at 46ch; prices tabular bone at md, right-seated on desktop.

### Evento Row
`hm-evento`: a 3.6rem date column (Italiana XL brass, spanning two rows) beside a bone title (460) and a small smoke detail line, hairline-ruled below, M vertical padding. Used for events and loyalty steps.

### Voice (`hm-voz`)
Statement lines in Italiana at XL, 1.18 line-height, bone, balanced wrapping — the café speaking in first person between sections.

### Inputs / Fields
Char wells: `char` background, 1px hairline border, radius 0, bone text, 0.85rem 1rem padding, smoke placeholders at full opacity. Focus swaps the border to brass and adds a 1px brass ring (`box-shadow: 0 0 0 1px brass`) with no default outline. Global focus-visible elsewhere is a 2px brass outline offset 3px.

### Reveal Grammar (signature motion)
One grammar page-wide. `hm-reveal` elements enter by rising 22px, unblurring from 5px, and fading in over the slow duration (900ms) on the standard easing `cubic-bezier(0.16, 1, 0.3, 1)`; siblings inside an `hm-reveal-group` stagger by 120ms per child (up to 480ms). Full-bleed covers reveal by light alone — no blur, no translate. Cover photography settles from scale 1.045 to 1 over 2600ms. Elements already inside the first viewport reveal immediately on load; the IntersectionObserver (`-10%` bottom margin, 0.12 threshold) fires once per element and unobserves. Under `prefers-reduced-motion`, every reveal, settle, smooth-scroll, and the candle veil are removed — the page is complete and static.

### Footer
Noche ground opened by a top hairline; smoke links hovering to soft brass; bone site title; a 34ch tagline; a hairline-ruled base row with small legal links.

## Do's and Don'ts

### Do:
- **Do** use only real café photography — the cinematic low-light images shot at Haramara (pour, celosía kombucha, interior, pastry plates). The brand seal is the gold flame logo raster, circular, never redrawn.
- **Do** put real information in structural type: station rows carry actual opening hours, carta rows carry actual MXN prices in tabular figures, the hero's hours line is the true schedule (Miércoles a lunes · 8:00–20:00).
- **Do** draw structure with hairlines (`rgba(239,232,220,0.14)`) and dotted leaders; separate sections by changing the ground color, not by boxing content.
- **Do** keep noche (#0D0C0A) as the text color on every brass fill, and verify WCAG AA for every new pair on the dark grounds (smoke on carbon = 6.9:1 is the dimmest sanctioned body pair).
- **Do** route every entrance through the one reveal grammar (900ms rise-and-unblur, 120ms stagger) and give reduced-motion users the complete static page.
- **Do** treat `theme.json` as the single source of truth for tokens — four hand-synced copies exist in the wider monorepo; change tokens there first and propagate.

### Don't:
- **Don't** box content in cards, panels, or filled containers — no card grids, no container shadows, no rounded corners anywhere (radius tokens are 0).
- **Don't** add decorative kickers or eyebrow labels. The station row silhouette is reserved for information-carrying hours; a label with nothing to say does not get the treatment.
- **Don't** introduce bright saturated color, cool greys, pure black, or any light-mode surface. New accents come from the material palette (clay, olive) or nowhere.
- **Don't** intensify the candle veil past its 0.2 alpha ceiling or linearize its `progress^1.5` easing — its faintness is a recorded intent, not an oversight.
- **Don't** design or build reservations UI in any form — an owner decision, not a deferral.
- **Don't** hardcode menu content into templates or styles — specials rotate monthly; the menu is editorial content flowing through the carta and ladder components.
