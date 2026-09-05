# MultiWander Packages

Pulls Travel Compositor Holiday Packages into MultiWander.com.

You enter a package ID on a country page. The plugin fetches the package from
Travel Compositor, creates the package page underneath that country page in
Polish and English, links the two as Polylang translations, and renders the
offer preview row.

---

## 1. Install

1. In WordPress: **Plugins → Add New → Upload Plugin**
2. Choose `multiwander-packages.zip`
3. **Install Now**, then **Activate**

## 2. Add the credentials

Open `wp-config.php` and paste these **above** the line that says
`/* That's all, stop editing! */`:

```php
define( 'MW_TC_USERNAME',  'your-api-username' );
define( 'MW_TC_PASSWORD',  'your-api-password' );
define( 'MW_TC_MICROSITE', 'momiratravel' );
define( 'MW_TC_BASE_URL',  'https://online.travelcompositor.com/resources' );
```

The username and password are the same ones the `momira-tc-tool` Streamlit app
uses (`TRAVELC_USERNAME` / `TRAVELC_PASSWORD` in its Secrets).

They live in `wp-config.php` rather than the database on purpose: nothing
sensitive ends up in a database export, and no admin screen can reveal them.

**Check it worked:** go to **Settings → MultiWander Packages**. It should say
*Connected*.

## 3. Add packages to a country page

1. Edit a country page, e.g. `/azja-wakacje/tajlandia/`
2. Find the **"Travel packages on this page"** box below the editor
3. Paste one package per line — either the bare ID or the whole address:

   ```
   59875907
   https://momira.travel/en/idea/62837980/thailand-culinary-journey
   ```

4. Put `[multiwander_offers]` in the page content where the offer row should go
5. **Update**

You enter the ID **once**. The plugin fetches the package in Polish and in
English and creates both pages, linked as Polylang translations. If you open
the English country page, the ID box points you back to the Polish one — IDs
are always managed there, because the Polish country page is the parent the
package pages hang off.

The package pages are created immediately. Their addresses look like:

```
/azja-wakacje/tajlandia/wietnam-kambodza-od-hanoi-po-angkor-wat/
```

The slug comes from the Polish title. English pages are created alongside them
under the English country page and linked as Polylang translations.

### Teaser layouts

The same shortcode renders three ways:

```
[multiwander_offers]                        three-column row (default)
[multiwander_offers layout="slider"]        swipeable carousel — good for the home page
[multiwander_offers layout="single"]        one large featured offer
```

Other attributes: `columns="2|3|4"` (row only), `limit="6"`,
`heading="Najpopularniejsze podróże"`, and `ids="59875907 62837980"` to show
specific packages regardless of which page the shortcode sits on — that last
one is how you put offers on the home page.

Cards and their buttons link to the package page on multiwander.com. The
booking hand-off to Travel Compositor happens on the package page itself.

---

## Page template — do this once

**Settings → MultiWander Packages → Package pages.**

Package pages print their own `<h1>` over the hero image. If the theme
template *also* prints the page title, every package page ends up with two H1
headings, which weakens it in search. Pick a template that does not display
the title — on this theme, **"Page Builder (Transparent Header, Without
Title)"** — and every synced package page gets it automatically.

---

## SEO

- **Structured data**: each package page emits schema.org `TouristTrip`
  JSON-LD with the price, trip length and the places visited, in the same
  currency the visitor sees. This is what can earn a rich result in Google;
  Yoast cannot generate it because it does not know what a travel package is.
- **Meta description**: generated on first sync from the package's own data
  ("13-dniowa podróż przez Hanoi, Hue, Da Nang. Już od 10 800 zł za osobę…").
  A description you write by hand is never overwritten.
- One H1, H2 per section, descriptive `alt` text on every image, and a link
  from each package page back up to its country page.

---

## How it behaves

**Removing an ID** drops the card from the country page but leaves the package
page published — no dead URLs, no lost rankings. Delete the page yourself if
you actually want it gone.

**Re-syncing.** Data refreshes when you update the country page. There is also
a **Re-sync now** button in the same box for when you want fresh prices without
editing anything.

**Renaming a URL.** Edit the slug as usual, then tick *"Keep this URL"* in the
package page sidebar. Later syncs will leave it alone.

**Images.** The hero image is copied into your media library on first sync, so
it gets WordPress's resizing and lazy-loading and survives Travel Compositor
changing its URLs. You can override it per page in the sidebar.

**Prices.** Travel Compositor returns EUR for this microsite. Polish pages
convert to PLN using the theme's existing `get_eur_to_pln_exchange_rate()` in
`inc/exchange-rate.php` — one rate source, not two. English pages show EUR.

**Speed.** The front end never calls the API. Pages read from post meta written
at sync time, so a slow Travel Compositor can't slow the site down.

---

## Warnings you may see

**"This package has no Polish translation in Travel Compositor"** — the Polish
and English titles came back identical, meaning the package was never
translated. The Polish page will show an English title and get an English
slug. Translate it in Travel Compositor (the `momira-translation-sync` tool
does this), then hit **Re-sync now**.

**"Travel Compositor marks this package as inactive"** — the package still
renders, but it is switched off on the Travel Compositor side.

**"Itinerary detail unavailable"** — the title, description, price and hero
image loaded, but the day-to-day call failed. The page works; re-sync later.

---

## When a field looks wrong

**Settings → MultiWander Packages → Inspect a package.** Enter an ID and you
get what the plugin read next to the complete raw JSON, so you can see whether
the problem is the data or the parser.

---

## Notes

- Requires WordPress 6.0+ and PHP 7.4+.
- Polylang is optional. Without it, only Polish pages are created.
- Package pages are ordinary Pages, marked *Travel package* in the Pages list.
  They are Pages rather than a custom post type because a CPT cannot have a
  Page as its parent — producing `/azja-wakacje/tajlandia/{slug}` with a CPT
  would need hand-written rewrite rules and permalink filters kept working
  against the cache plugin, Yoast and breadcrumbs. Core page hierarchy already
  does this correctly.
- The old `[multiwander_offer]` shortcode in the child theme is untouched.
  Remove those shortcodes from a country page once you've added its IDs here.

---

## What this replaces

The previous offer cards fetched `mwpl.vna.de` and regex-scraped the page
`<title>` for the name and price, on every uncached page load, with SSL
verification disabled, and linked visitors off-site. This version uses the
real API, caches everything, keeps visitors on multiwander.com, and gives each
package an indexable page of its own.
