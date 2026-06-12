<img src=".wordpress-org/icon-256x256.png" alt="Coywolf Data Visualizer logo" width="128" />

# Coywolf Data Visualizer

Turn raw data into publish-ready charts without leaving WordPress. Upload a CSV, Excel, or JSON file, tell Claude what the data represents, and it designs a set of [Chart.js](https://www.chartjs.org/) charts with titles and captions. Pick the ones you like, save them, and embed them in any post or page with the Coywolf Chart block.

- **Version:** 1.0.0
- **Requires WordPress:** 6.3+
- **Requires PHP:** 7.4+
- **License:** GPL-2.0-or-later

## Description

Coywolf Data Visualizer pairs Claude's data analysis with Chart.js rendering. Instead of hand-building chart configurations, you upload the data and describe it in plain language — Claude proposes three to six distinct charts (bar, line, pie, doughnut, radar, polar area, scatter, or bubble), each with a descriptive title and a one-to-two sentence takeaway caption. You review live previews, edit the titles and captions, and save the charts you want to keep.

Saved charts are reusable: embed the same chart in any number of posts and pages, and the All Charts screen shows you exactly where each one is used.

### Features

- **AI chart generation** — upload CSV, TSV, JSON, or Excel (.xlsx) data, add a short explanation, and click Analyze. Claude returns a set of distinct, insight-focused charts with titles and captions. You choose which to save.
- **Live previews before saving** — every suggestion renders as a real Chart.js chart on the Add Chart screen, with editable titles and captions.
- **Coywolf Chart block** — a searchable picker modal (type to filter, click to choose) embeds any saved chart. Per-block display settings: show/hide the title, caption, and legend, legend position, max width, and a fixed height.
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

- **Anthropic Claude API** (`api.anthropic.com`) — when you click Analyze, the parsed contents of your uploaded data file (capped at roughly the first 800 rows) and your explanation are sent to the Claude API to generate chart suggestions. The Settings screen also fetches the list of models available to your key and uses it for the connection test. Data is sent only when you initiate these actions, authenticated with the API key you supply. See Anthropic's [terms of service](https://www.anthropic.com/legal/consumer-terms) and [privacy policy](https://www.anthropic.com/legal/privacy). API usage is billed to your Anthropic account.
<!-- wporg-strip:start -->
- **GitHub** (`api.github.com` / `github.com`) — the GitHub distribution of the plugin checks the project's GitHub Releases so updates appear on Dashboard → Updates. Only the release metadata is fetched; no site data is sent.
<!-- wporg-strip:end -->

No other external requests are made. Your data files are never stored by the plugin.

## FAQ

### What file formats can I upload?

CSV, TSV, plain-text delimited files, JSON (an array of objects or an array of arrays), and Excel `.xlsx` workbooks (first sheet). Files are capped at 10 MB, and very large tables are truncated to a representative excerpt before being sent to Claude.

### Is my data sent anywhere?

Only when you click Analyze, and only to the Claude API (see External services above). The uploaded file is parsed in memory and discarded — the plugin never stores it. Saved charts contain only the aggregated values Claude embedded in each chart configuration.

### What does it cost?

The plugin is free. Claude API usage is billed by Anthropic to your account; a typical analysis is a single API call.

### Which chart types are supported?

Bar, line, pie, doughnut, polar area, radar, scatter, and bubble — the core Chart.js v4 types.

### Can I change how a chart looks in a specific post?

Yes. The block's settings panel controls the title, caption (including a per-block caption override), legend visibility and position, max width, and height for that embed without changing the saved chart.

### What happens when I delete a chart?

The plugin finds every post and page embedding that chart, removes the block from their content, and updates them — the same way the Coywolf Custom Blocks plugin handles deletions.

### Does it work with the classic editor?

The Coywolf Chart block requires the block editor. Charts can't be embedded via shortcode in this version.

## Changelog

### 1.0.0
- Initial release: Claude-powered chart generation from CSV/TSV/JSON/XLSX uploads, live suggestion previews, the Coywolf Chart block with a searchable picker and per-block display settings, the All Charts screen with usage tracking and safe deletes, and the Settings and Documentation screens.
