# Honeytreat Trade Academy — Website

The public website for **Honeytreat Trade Academy (HTA)**, a vocational training academy offering internationally recognised certifications in construction, digital, agripreneur and entertainment skills, with centres across Lagos, Ibadan, Osun and Abuja.

🌐 **Live site:** [honeytreatacademy.com](https://honeytreatacademy.com)  
🎓 **LMS portal:** [play.honeytreatacademy.com](https://play.honeytreatacademy.com)

---

## Pages

| Page | File | What it covers |
|---|---|---|
| Home | `index.html` | Hero, programme highlights, partners, calls to enrol |
| About | `about.html` | Story, leadership and team, accreditations |
| Courses | `courses.html` | The four skill tracks, centres per track, and the enrolment form |
| Gallery | `gallery.html` | Photos from classes, field work and graduations |
| Testimonials | `testimonials.html` | Alumni stories |
| Blog | `blog.html` | News and articles |
| Contact & Enrol | `connect.html` | Contact form, centre addresses, phone and socials |

Legal documents linked in the footer: `privacy-policy.pdf`, `terms.pdf`, `qms-policy-statement.pdf`.

## Tech stack

The site is plain **HTML, CSS and vanilla JavaScript**. There is no build step: what's in the repo is what gets served.

- **`styles.css`** — the editable stylesheet. Pages load **`styles.min.css`**, a minified copy (see [Editing styles](#editing-styles)).
- **`main.js`** — navbar scroll effect, mobile menu, course tabs and other page interactions.
- **`hta-bot.js`** — *HTA Assistant*, a self-contained on-site help bot (replaced Tawk.to; no third-party scripts or tracking).
- **`enroll-brevo-v2.php`** — server endpoint that receives enrolments from `courses.html` and sends them to Brevo.
- **Formspree** — handles the contact form on `connect.html` and the bot's "Ask the team" messages.
- **Google Fonts** — Playfair Display and DM Sans.

## Project structure

```
hta-website/
├── index.html, about.html, courses.html,     # site pages
│   gallery.html, testimonials.html,
│   blog.html, connect.html
├── styles.css            # source stylesheet (edit this)
├── styles.min.css        # minified copy the pages load
├── main.js               # site interactions
├── hta-bot.js            # HTA Assistant help bot
├── enroll-brevo-v2.php   # enrolment endpoint (Brevo)
├── secrets/              # server config — git-ignored, never commit
│   └── brevo-config-v2.php
├── Images/               # hero images
├── *.webp / *.jpg / *.png  # page images (WebP versions are what pages use)
├── *.pdf                 # privacy policy, terms, QMS policy
├── hta_speed_patch.py    # image/CSS optimisation script
├── hta_speed_report.txt  # output from the last optimisation run
└── src/, public/, package.json   # React registration form (see below)
```

## Running locally

Because the site is static, you can open `index.html` directly in a browser. To test the **enrolment form**, you need PHP, since it posts to `enroll-brevo-v2.php`:

```bash
# from the repo root
php -S localhost:8000
```

Then open <http://localhost:8000>. The endpoint already accepts requests from `localhost` and `127.0.0.1`.

## Enrolment flow (Brevo)

When a visitor submits the **Enroll Now** form on `courses.html`, the page sends one request to `enroll-brevo-v2.php`, which:

1. Creates or updates the contact in **Brevo** (name, email, phone, course, centre) and optionally adds them to a list, which can trigger a Brevo automation.
2. Sends a custom Brevo event, which can trigger a second automation.
3. Emails the selected centre a notification using Brevo transactional email (falls back to a central inbox if the centre isn't recognised).

The script rejects non-POST requests and unknown origins, drops bot submissions caught by a honeypot field, and does not log personal data.

### Configuration

The endpoint reads its settings from `secrets/brevo-config-v2.php`. This folder is **git-ignored**, so create the file by hand on each server:

```php
<?php
if (!defined('HTA_BREVO')) { http_response_code(403); exit; }

define('BREVO_API_KEY',      'your-brevo-api-key');
define('HTA_ALLOWED_HOSTS',  'honeytreatacademy.com,www.honeytreatacademy.com');

// Optional
define('BREVO_LIST_ID',      0);                       // list to add new enrolees to
define('BREVO_EVENT_NAME',   'course_enrolment');      // custom event name
define('BREVO_SENDER_EMAIL', 'contact@honeytreatacademy.com'); // must be verified in Brevo
define('BREVO_SENDER_NAME',  'HTA Website');
define('HTA_FALLBACK_EMAIL', 'contact@honeytreatacademy.com');
```

> ⚠️ Never commit API keys. If a key is ever pushed to GitHub, rotate it in Brevo straight away.

## Editing content

- **Text and sections:** edit the relevant `.html` file directly.
- **Courses and centres:** each track's **Enroll Now** button in `courses.html` lists the centres for that track in its `openEnrollModal(...)` call. Update it there when a centre is added or closed.
- **Help bot answers:** edit the `CONFIG`, `CENTRES` and `TRACKS` blocks at the top of `hta-bot.js`. Nothing else in that file needs to change.
- **Contact details:** the phone number (+234-807-334-2278) and email (contact@honeytreatacademy.com) appear across pages, the bot config and the PHP endpoint. Search the whole repo when changing them.

### Editing styles

Make changes in `styles.css`, then rebuild the minified file:

```bash
python hta_speed_patch.py --css
```

If you skip this step, your CSS changes won't show on the live site.

### Adding images

Pages use **WebP** images. When you add a new JPG or PNG, either convert it to WebP yourself or run the optimisation script:

```bash
pip install pillow
python hta_speed_patch.py --dry-run   # preview changes
python hta_speed_patch.py             # apply
python hta_speed_patch.py --restore   # undo using the latest backup
```

The script converts and resizes images, adds `width`/`height` and lazy loading to `<img>` tags, and backs up every file it touches to `_hta_backup_<date-time>/` first. Originals are kept.

## Deployment

The site is currently hosted on **cPanel**. To deploy, upload the changed files to the site's document root (`public_html`) through the File Manager or FTP, keeping the folder structure intact. Make sure `secrets/brevo-config-v2.php` exists on the server.

**Hosting note:** the enrolment form needs PHP. Static-only hosts (GitHub Pages, Netlify or Vercel static hosting) will serve the pages but **the enrolment form will stop working** unless `enroll-brevo-v2.php` is moved to a serverless function or another PHP host.

## React registration form (`src/`, `public/`, `package.json`)

The repo also contains a small Create React App project (`honeytreat-portal`) that holds a multi-step organisation registration form. It is separate from the static site, and none of the site pages depend on it.

```bash
npm install
npm start          # dev server at http://localhost:3000
npm run build      # production build in /build
npm run deploy     # publishes /build to GitHub Pages (gh-pages)
```

## Branches

- `main` — the live site
- `feature/*` — work in progress (for example, `feature/brevo-enrolment`). Open a pull request into `main` when it's ready.

## Related

- **HTA Academy LMS:** [play.honeytreatacademy.com](https://play.honeytreatacademy.com)
- **Honeytreat Group:** [honeytreatgrp.com](https://honeytreatgrp.com)
- **Socials:** [Facebook](https://www.facebook.com/Honeytreattradeacademy) · [Instagram](https://www.instagram.com/honeytreatacademy) · [X](https://x.com/htaacademy01) · [YouTube](https://www.youtube.com/channel/UCb0mH7NeYaNTUYL1LRljejA)

---

© Honeytreat Trade Academy. All rights reserved.
