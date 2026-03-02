# RRZE Answers

[![Version](https://img.shields.io/github/package-json/v/rrze-webteam/rrze-answers/main?label=Version)](https://github.com/RRZE-Webteam/rrze-answers)
[![Release Version](https://img.shields.io/github/v/release/rrze-webteam/rrze-answers?label=Release+Version)](https://github.com/RRZE-Webteam/rrze-answers/releases/)
[![GitHub License](https://img.shields.io/github/license/rrze-webteam/rrze-answers)](https://github.com/RRZE-Webteam/rrze-answers)
[![GitHub issues](https://img.shields.io/github/issues/rrze-webteam/rrze-answers)](https://github.com/RRZE-Webteam/rrze-answers/issues)

---

## Overview

**RRZE Answers** combines the functionalities of the former plugins **RRZE FAQ**, **RRZE Glossary**, and **RRZE Synonym** into a single solution.

It allows you to:
- Create and display FAQs, glossary entries, and placeholders  
- Synchronize content between websites in the FAU network  
- Display entries using shortcodes, Gutenberg blocks, or widgets  
- Filter and group entries by categories, tags, or domains  
- Integrate with the WordPress REST API (v2)
- Can improve your ranking on Google with integrated SEO optimization using structured data 

---

## Features

- **Unified content management:** FAQs, Glossary entries, and Placeholders are managed in one place.  
- **Flexible display options:** Accordion view, A–Z index, tabs, tag cloud or grid.
- **Cross-domain synchronization:** Share and import entries from other FAU sites.  
- **REST API support:** Access entries programmatically.  
- **Multilingual and SEO-friendly:** Uses [`schema.org/FAQPage`](https://schema.org/FAQPage) for faq entries, [`schema.org/DefinedTerm`](https://schema.org/DefinedTerm) for glossary entries and `<abbr>` tags for placeholders.  

---

## Blocks

**RRZE FAQ Block**
The FAQ block lets you display selected FAQ entries and control how they appear on the page. In the settings, you can choose specific items, filter by categories or tags, and select a glossary or grouping style such as alphabetical lists, tabs, or tag clouds. You can hide interface elements like titles, accordions, or the glossary navigation, enable a masonry grid layout, and add optional CSS or faculty classes. Sorting options and the starting heading level can also be adjusted to match your page structure.

**RRZE FAQ Widget**
This block lets you display either a selected FAQ entry or a random one from a chosen category. In the settings, you can pick a specific item or filter by categories. You may also choose to show the answer without displaying the question.

**RRZE Glossary Block**
The Glossary block displays glossary entries and offers similar flexibility. You can select entries, filter them by categories or tags, and control the register or grouping style, including A–Z lists, tab navigation, and tag cloud layouts. Display elements such as titles, accordion views, or the register can be hidden, while optional features like “expand all” or opening entries by default can be enabled. Additional styling classes, sorting behavior, and heading levels can be configured as needed.

**Using placeholders**
The text editor provides a dedicated menu entry for inserting placeholders. From there, you can select any available placeholder from a list and insert it directly into your content.

---

## Shortcodes

### FAQ Shortcode

```html
[faq id="456,123"]
[faq category="category-1"]
[faq tag="tag-1,tag-2"]
[faq category="category-1" tag="tag-2"]
```

**Attributes:**
- `glossary` – Grouping type (`category`, `tag`, or display style: `a-z`, `tabs`, `tagcloud`)
- `category` – One or more category slugs  
- `tag` – One or more tag slugs  
- `domain` – Filter by domain(s)  
- `id` – Specific FAQ IDs  
- `hide` – Hide elements (`accordion`, `title`, `glossary`)  
- `masonry` – Grid layout (`true`/`false`)  
- `search`: Shows a search input above the FAQ list to filter questions. (`true`/`false`)  
- `lang`: Filters by language (2 char code, f.e. 'de' or 'fr') 
- `class` – Faculty or custom CSS classes (`fau`, `med`, `nat`, etc.)  
- `sort` – Sort by `title`, `id`, or `sortfield`  
- `order` – Sort direction (`asc`, `desc`)  
- `hstart` – Heading level (default: 2)

---

### Glossary Shortcode

```html
[glossary id="123,456"]
[glossary category="kategorie-1"]
[glossary tag="schlagwort-1,schlagwort-2"]
```

**Attributes:**
- `register` – Grouping type (`category`, `tag`) and style (`a-z`, `tabs`, `tagcloud`)
- `category` – One or more categories  
- `tag` – One or more tags  
- `id` – Specific entries by ID  
- `lang`: Filters by language (2 char code, f.e. 'de' or 'fr') 
- `hide` – Hide output elements (`accordion`, `title`, `register`)  
- `show` – Display options (`expand-all-link`, `load-open`)  
- `class` – Border color / CSS classes  
- `sort`, `order`, `hstart` – As above  

---

### Placeholder Shortcodes

```html
[placeholder id="123"]
[placeholder slug="bildungsministerium"]
[fau_abbr id="987"]
[fau_abbr slug="url"]
```

**Attributes:**
- `id` – Display a specific placeholder or abbreviation  
- `slug` – Use the entry’s slug  
- No attributes → list all placeholders  

The `[fau_abbr]` shortcode outputs abbreviations as `<abbr>` HTML tags, including language and pronunciation details if specified.

Example:
```html
<abbr title="Universal Resource Locator" lang="en">URL</abbr>
```

---

## Synchronization Across Domains

External domains can be added and synchronized via:

```
Settings → RRZE Answers → Domains
Settings → RRZE Answers → Import
```

Entries from synchronized domains behave like local entries and can be displayed via shortcode, block, or widget.

---

## Widgets

- **Answers Widget:** Show a specific or random FAQ or glossary entry.  
- Configurable options include display duration, layout, and category selection.

---

## REST API (v2)

### FAQ
- All:  
  `https://www.anleitungen.rrze.fau.de/wp-json/wp/v2/faq`
- Filtered:  
  `https://www.anleitungen.rrze.fau.de/wp-json/wp/v2/faq?filter[rrze_faq_tag]=Matrix`

### Glossary
- All:  
  `https://www.anleitungen.rrze.fau.de/wp-json/wp/v2/glossary`
- Category + Tag:  
  `https://www.anleitungen.rrze.fau.de/wp-json/wp/v2/glossary?filter[rrze_glossary_category]=Dienste&filter[rrze_glossary_tag]=Sprache`

**Pagination:**  
Refer to [WordPress REST API Pagination](https://developer.wordpress.org/rest-api/using-the-rest-api/pagination/)

---

## License

Licensed under the [GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html).

---

## Credits

Developed and maintained by the  
**RRZE Webteam, Friedrich-Alexander-Universität Erlangen-Nürnberg (FAU)**  
👉 [https://github.com/RRZE-Webteam/rrze-answers](https://github.com/RRZE-Webteam/rrze-answers)
