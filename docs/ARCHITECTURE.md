<p align="center">
  <img src="https://capsule-render.vercel.app/api?type=waving&color=0:0B0F19,35:111827,70:F97316,100:0B0F19&height=260&section=header&text=RB%20Personal%20Trainer&fontSize=56&fontColor=FFFFFF&animation=fadeIn&fontAlignY=38&desc=Production%20static%20website%20%E2%80%A2%20SEO-first%20%E2%80%A2%20Performance-focused%20%E2%80%A2%20Accessibility-aware&descAlignY=66&descSize=18" />
</p>

<p align="center">
  <img src="https://readme-typing-svg.demolab.com?font=Fira+Code&size=20&pause=900&color=F97316&center=true&vCenter=true&width=980&lines=Client-grade+static+site+for+a+fitness+%2F+health+brand;Engineered+for+SEO%2C+speed%2C+and+Apache%2FcPanel+reliability;Orange+%26+Black+theme+with+gym-grade+presentation+for+GitHub;No+frameworks.+No+build+step.+Clean+delivery." />
</p>

<p align="center">
  <a href="https://rbpersonaltrainer.com">
    <img src="https://img.shields.io/badge/Production-Live-F97316?style=for-the-badge&logo=vercel&logoColor=white" />
  </a>
  <a href="https://danmarques127-sys.github.io/rbpersonaltrainer/">
    <img src="https://img.shields.io/badge/GitHub%20Pages-Preview-111827?style=for-the-badge&logo=github&logoColor=white" />
  </a>
  <a href="https://github.com/danmarques127-sys">
    <img src="https://img.shields.io/badge/Author-DaNgelo%20Marques-0B0F19?style=for-the-badge&logo=github&logoColor=F97316" />
  </a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Theme-Orange%20%26%20Black-F97316?style=for-the-badge&labelColor=0B0F19&color=F97316" />
  <img src="https://img.shields.io/badge/Hosting-Apache%20%2F%20cPanel-111827?style=for-the-badge&logo=apache&logoColor=white" />
  <img src="https://img.shields.io/badge/HTTPS-SSL%20Enabled-22C55E?style=for-the-badge&logo=letsencrypt&logoColor=white" />
</p>

<p align="center">
  <img src="https://capsule-render.vercel.app/api?type=rect&color=0:0B0F19,50:F97316,100:0B0F19&height=2&section=header" />
</p>

# RB Personal Trainer — Architecture

This is a client-grade **static multi-page website** built for predictable deployment on **Apache/cPanel** and previewed via **GitHub Pages**.

---

## Routes / Pages
Primary pages are flat HTML routes at the repo root (e.g. `/index.html`, `/about.html`, `/contact.html`, etc.).

**Design decision:** plain routes keep hosting portable and reduce operational complexity (no build step, no runtime dependencies).

---

## Layout Consistency (header/footer)
Because this is a static site (no templating engine), shared UI (header/footer) is repeated across pages.

**Why:** This keeps the site framework-free and compatible with simple hosting.
**How we keep it maintainable:**
- Consistent HTML structure and class naming across pages
- Shared CSS in `assets/css/`
- Shared JS in `assets/js/` (only for lightweight behavior)

> If/when needed, the project can evolve to a templated build step, but current goal is maximum portability.

---

## Data Source (blog / cards / dynamic content)
If the site has “blog/cards” or repeated content blocks, data can be stored as:
- a small JS array in `assets/js/` (simple, portable), or
- plain HTML sections (best for pure SEO), depending on needs.

**Decision guideline:**
- SEO-critical content: render directly in HTML
- UI-only repeat blocks: a small JS data layer is acceptable

---

## SEO Decisions
- Semantic HTML (landmarks, headings, meaningful link text)
- Per-page metadata (title, description, canonical)
- Open Graph / Twitter Cards
- `/seo/robots.txt` and `/seo/sitemap.xml` versioned and validated

---

## Performance Decisions
- Images organized under `assets/img/` (flattened, no duplicated nesting)
- Icons under `assets/icons/` (favicons + manifest-related assets)
- `.htaccess` caching strategy for faster repeat visits

---

## Cache & Hosting (Apache)
`.htaccess` provides:
- Long cache for static assets (CSS/JS/images/icons)
- Shorter cache for HTML to avoid stale pages after updates
- Optional security headers (depending on server compatibility)

---

## Asset Organization (rules)
- No `images/` or `/icons/` legacy paths inside HTML
- Use:
  - `assets/img/...`
  - `assets/icons/...`
- Avoid duplicated folders:
  - ✅ `assets/img/*`
  - ❌ `assets/img/images/*`
  - ✅ `assets/icons/*`
  - ❌ `assets/icons/icons/*`

---

## Deployment
### GitHub Pages (Preview)
Deployed from `main` root folder.

### Production (Apache / cPanel)
Upload to `public_html/` and ensure `.htaccess` overrides are enabled.

---

## Maintenance Notes
- Keep navigation links consistent across pages
- Validate links periodically (CI workflow recommended)
- Any empty placeholder page (e.g. `spot4.html`) must be removed or replaced with a real placeholder and removed from nav

<p align="center">
  <img src="https://capsule-render.vercel.app/api?type=waving&color=0:0B0F19,60:F97316,100:0B0F19&height=120&section=footer" />
</p>
