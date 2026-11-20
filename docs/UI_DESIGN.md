# Adult Core visual design system — 0.8.5-ui-dev

**Status:** Implemented in repository. The UI is a development preview, not production certification. No identity or billing functionality is enabled by visuals.

## Direction

A premium, restrained dark interface: deep charcoal-navy surfaces, soft violet highlights, muted teal/antique-gold secondary accents, large editorial typography, airy margins, and subtle CSS-only ornamental art. Avoid erotic imagery or claims of verified membership features. The product must feel trustworthy as well as premium.

## Architecture

- `src/UI/Layout.php` owns the four common shells: public site, member portal, unauthenticated administrator, and authenticated administrator. All paths are currently server-rendered PHP. The admin sidebar is strictly visual; controllers remain responsible for access control and CSRF.
- `src/UI/Dashboard.php` provides the actual administrator dashboard components, using data supplied from the existing database after authorization. Do not show fake growth metrics, sales revenue or subscription approvals.
- `public/assets/style.css` contains base compatibility selectors and UI design tokens/styles. No external CSS imports, JS framework, webfonts or browser trackers. All decorative hero artwork is HTML + CSS.
- `/theme.css` keeps the existing signature-verified, runtime-gated theme CSS mechanism. The premium base still works without a selected theme.
- `src/Website/PublicSite.php` displays the installed `site_name` on the public brand, keeping Adult Core's own admin-brand identity in the backend.

## Component and color palette

- Background `#090d18`, dark card `#151b2b`, raised surface `#171d30`, text `#f6f5fc`, secondary text `#a5acc2`, lavender accent `#a994ff`, mint `#8bdfd6`, warm gold `#e5c69a`.
- Form controls: visible labels, at least 42px targets, distinct focus rings and a clear primary call-to-action.
- Admin: 253px persistent desktop sidebar; collapses into horizontally scrollable section navigation on mobile. Dashboard uses 4 real-data summary cards and quick-access links. Page editor uses a two-column form/help layout that stacks on narrower screens.
- Public: branded header, responsive editorial two-column hero, CSS-only design monogram, meaningful site-managed page text, calls-to-action and simple footer. The hero does not display private media.

## Accessibility and performance

- HTML5 landmarks, keyboard skip navigation, `aria-current=page` on selected navigation, labelled sections and no hover-only interactions.
- `:focus-visible` treatment, readable contrast, and `prefers-reduced-motion` behavior. Real device/screen-reader audits remain required before release.
- Mobile breakpoints at 1200px, 960px, 760px, and 480px. Avoid horizontal scrolling even with decorative orbits.
- No external HTTP dependencies from the theme foundation. A signed installed stylesheet can change presentation without weakening the access gate.

## Preview and verification

The optional browser screenshots supplied with this milestone were captured from `Layout::site()`, `Layout::admin()` and `Dashboard::render()` using **illustrative data only** (e.g. a fictional site name and test email). They are not screenshots of a configured customer website and do not imply live memberships, payment activity, approved content or complete age verification.

Run `php tests/ui_tests.php` plus the regular full suite and MySQL CI. The screenshots should be visually reviewed at desktop and 390px mobile widths. The production readiness script must remain fail-closed.
