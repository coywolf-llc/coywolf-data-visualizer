<img src=".wordpress-org/icon-256x256.png" alt="Coywolf Data Visualizer logo" width="128" />

# Coywolf Data Visualizer

Turn raw data into publish-ready charts without leaving WordPress. Upload a CSV, Excel, or JSON file and get a set of suggested [Chart.js](https://www.chartjs.org/) charts with titles and captions — designed instantly by the built-in analyzer, or by Claude when you connect an API key (optional, best results). Pick the ones you like, save them, and embed them in any post or page with the Coywolf Chart block.

- **Version:** 1.3.5
- **Requires WordPress:** 6.3+
- **Requires PHP:** 7.4+
- **License:** GPL-2.0-or-later

## Description

Coywolf Data Visualizer turns data files into Chart.js charts. Out of the box it needs no account and no API: the built-in analyzer profiles your columns (dates, numbers, categories) and proposes up to six charts — time series, category comparisons, share-of-total, top-10 rankings, correlations — with captions computed from the data itself (trends, leaders, correlation strength).

Connect a Claude API key (optional) and Claude designs the charts instead: it reads your data *and* your plain-language explanation, picks the most insightful angles, aggregates intelligently, and writes real takeaway captions. Either way you review live previews, edit the titles and captions, and save the charts you want to keep.

The Claude API key can be saved in the database or, for better security, defined as the `ANTHROPIC_API_KEY` constant in `wp-config.php` (or an environment variable). The Settings page shows where the active key comes from and confirms when it's configured.

Saved charts are reusable: embed the same chart in any number of posts and pages, and the All Charts screen shows you exactly where each one is used.

### Features

- **Built-in chart analyzer (no API needed)** — upload CSV, TSV, JSON, or Excel (.xlsx) data and click Analyze. The analyzer detects column types and generates line, bar, doughnut, radar, and scatter suggestions with data-derived captions — instantly and free.
- **Claude engine (optional, best results)** — add a Claude API key and Claude designs the charts from your data plus a plain-language explanation: smarter chart choices, intelligent aggregation, and written insight captions. Switch engines per analysis on the Add Chart screen.
- **Live previews before saving** — every suggestion renders as a real Chart.js chart on the Add Chart screen, with editable titles and captions.
- **Coywolf Chart block** — a searchable picker modal (type to filter, click to choose) embeds any saved chart. Per-block display settings: show/hide the title, caption, and legend, legend position, max width, and a fixed height.
- **Color schemes** — nine bundled palettes (Coywolf, Tableau 10, the Okabe–Ito color-blind-safe set, ColorBrewer Set2/Dark2/Pastel, D3 Category 10, Ocean, Sunset) plus your own: download any scheme as a .json file, tweak the colors, and upload it as a custom scheme. Pick a site default in Settings for new charts; switch any chart's scheme later without touching its data.
- **Edit Chart screen** — click any chart name on All Charts to rename it, rewrite its caption, change its color scheme, switch compatible chart types (bar ↔ line, pie ↔ doughnut ↔ polar area), flip bars horizontal, stack datasets, start the value axis at zero, or hide grid lines — with a live preview. Update, Cancel, and Delete all return to All Charts, and deleting removes the chart's block from every post and page that used it.
- **Site-wide chart appearance** — give every chart a background color (white by default, clearable to transparent) and rounded corners from Settings; the editor preview matches.
- **All Charts screen** — a standard WordPress table with search, a chart-type filter, pagination, and bulk delete. Posts and Pages columns count where each chart is embedded and link to a filtered post list.
- **Safe deletes** — deleting a chart also removes its block from every post and page that used it and updates those posts, so nothing renders broken embeds.
- **Accessible rendering** — charts render in a `<figure>` with the caption in a `<figcaption>` and a descriptive `aria-label` on the canvas. The Chart.js config is emitted as data attributes; the plugin prints no inline scripts.
- **Local storage** — charts are stored as a private post type in your WordPress database. Uploaded data files are parsed in memory and never written to disk.

## Installation

1. Upload the plugin and activate it.
2. Go to **Charts → Settings** and paste your Claude API key (create one in the [Anthropic Console](https://console.anthropic.com/)). Pick a model — the default is a solid choice.
3. Go to **Charts → Add Chart**, upload a data file, describe the data, and click **Analyze**.
4. Select the charts you want and click **Save selected charts**.
5. In the editor, add a **Coywolf Chart** block and pick a chart.

## External services

This plugin connects to the following external services:

- **Anthropic Claude API** (`api.anthropic.com`) — optional, used only when you have saved an API key and chosen the Claude engine. When you click Analyze, the parsed contents of your uploaded data file (capped at roughly the first 800 rows) and your explanation are sent to the Claude API to generate chart suggestions. The Settings screen also fetches the list of models available to your key and uses it for the connection test. Data is sent only when you initiate these actions, authenticated with the API key you supply. See Anthropic's [terms of service](https://www.anthropic.com/legal/consumer-terms) and [privacy policy](https://www.anthropic.com/legal/privacy). API usage is billed to your Anthropic account.
<!-- wporg-strip:start -->
- **GitHub** (`api.github.com` / `github.com`) — the GitHub distribution of the plugin checks the project's GitHub Releases so updates appear on Dashboard → Updates. Only the release metadata is fetched; no site data is sent.
<!-- wporg-strip:end -->

No other external requests are made — with no API key saved, the plugin makes none at all. Your data files are never stored by the plugin.

## FAQ

### What file formats can I upload?

CSV, TSV, plain-text delimited files, JSON (an array of objects or an array of arrays), and Excel `.xlsx` workbooks (first sheet). Files are capped at 10 MB, and very large tables are truncated to a representative excerpt before being sent to Claude.

### Does it work without a Claude API key?

Yes — that's the default. The built-in analyzer designs charts entirely on your server from the column types in your file. Adding a Claude API key unlocks the Claude engine, which produces the best results: smarter chart selection, aggregation, and written captions.

### Is my data sent anywhere?

Only when you choose the Claude engine and click Analyze, and only to the Claude API (see External services above). The built-in analyzer never sends anything off your server. The uploaded file is parsed in memory and discarded — the plugin never stores it. Saved charts contain only the aggregated values Claude embedded in each chart configuration.

### What does it cost?

The plugin and its built-in analyzer are free. If you opt into the Claude engine, API usage is billed by Anthropic to your account; a typical analysis is a single API call.

### Which chart types are supported?

Bar, line, pie, doughnut, polar area, radar, scatter, and bubble — the core Chart.js v4 types.

### Can I change how a chart looks in a specific post?

Yes. The block's settings panel controls the title, caption (including a per-block caption override), legend visibility and position, max width, and height for that embed without changing the saved chart.

### What happens when I delete a chart?

The plugin finds every post and page embedding that chart, removes the block from their content, and updates them — the same way the Coywolf Custom Blocks plugin handles deletions.

### Does it work with the classic editor?

The Coywolf Chart block requires the block editor. Charts can't be embedded via shortcode in this version.

## Changelog

### 1.3.5
- Keep the settings blob out of autoload (#12).

### 1.3.4
- Updater: run GitHub release checks in the background so the Updates screen never hangs (#11).

### 1.3.3
- Add docs-page capability check, de-autoload API key, add chart screen-reader table (#10).

### 1.3.2
- Settings: wp-config API key option + connection status indicator (#9).

### 1.3.1
- Integrate custom color schemes into the Chart appearance section (#8).

### 1.3.0
- Custom color schemes: download the selected scheme as a .json file from Settings, edit it, and upload it back as your own — custom schemes appear in every scheme picker and can be removed at any time (charts fall back to their original colors).
- Edit Chart actions: Cancel and Delete buttons join Update Chart; all three return to All Charts, and Delete strips the chart's block from every post and page that embedded it.
- All Charts: removed the ID from the row actions.

### 1.2.1
- Fix Edit Chart page: Sorry, you are not allowed to access this page (#6).

### 1.2.0
- Color schemes: nine palettes (Tableau 10, Okabe–Ito, ColorBrewer, D3 Category 10, and Coywolf originals) with a site default in Settings, applied non-destructively at render time.
- Edit Chart screen: chart names on All Charts now link to an editor with a live preview — rename, edit the caption, switch the color scheme, change compatible chart types, flip bars horizontal, stack datasets, begin the axis at zero, or hide grid lines.
- The same display options are available on each suggestion card when creating charts.

### 1.1.2
- Add chart background color and corner radius settings (#4).

### 1.1.1
- Built-in analyzer: pick the right category column in real-world exports (#3).

### 1.1.0
- Built-in chart analyzer: the plugin now works without a Claude API key. It profiles your columns (dates, numbers, categories) and designs time-series, category, share, top-10, radar, and correlation charts with captions computed from the data. The Claude engine is now optional — when a key is saved, choose either engine per analysis on the Add Chart screen.

### 1.0.0
- Initial release: Claude-powered chart generation from CSV/TSV/JSON/XLSX uploads, live suggestion previews, the Coywolf Chart block with a searchable picker and per-block display settings, the All Charts screen with usage tracking and safe deletes, and the Settings and Documentation screens.
