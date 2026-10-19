// The accessibility check of the website, a step of the check docs: it opens every
// page that MkDocs built into a folder, such as site/, in Chrome, in the light and in
// the dark scheme, and runs axe-core on each with the rules of WCAG 2.2 at the levels
// A and AA. A violation whose impact is serious or critical fails it, with the page,
// the rule, the elements and a link that explains the fix; the lesser ones it counts.
// Each scheme has a browser of its own, whose system shows that scheme, and each page
// loads in it as a new reader's would, without a scheme that an earlier page saved;
// a website whose palette doesn't follow the system switches with its toggle, and a
// page that shows neither way the scheme of its toggle is a violation as well. A page
// of a further language without a translation, whose text is that of the page in the
// default language, is checked through that page. The pages come from the folder
// alone: a request to the address of the website is answered from there, and one to
// another server is refused and named, so the check reads nothing from the network.
// After a build, on your machine:
//
//   npm ci --prefix .github/a11y --ignore-scripts
//   node .github/a11y/check.mjs site
//
// Pages after the folder, such as dashboard/index.html, check those pages alone.
// node --test .github/a11y/check.test.mjs tests the check itself.
//
// It drives the Google Chrome that GitHub's runners have; CHROME names another
// Chrome or Chromium, by the path of its program. REPORT_ONLY=true annotates the
// violations as warnings rather than errors, for a check that doesn't fail on them
// yet. Renovate keeps the tools current.
// https://github.com/Dennis-Otto/repo-blueprint

import { existsSync } from "node:fs";
import { appendFile, readdir, readFile } from "node:fs/promises";
import { availableParallelism } from "node:os";
import path from "node:path";
import process from "node:process";
import { pathToFileURL } from "node:url";

import { AxeBuilder } from "@axe-core/playwright";
import { chromium } from "playwright-core";

// The rules of WCAG 2.0, 2.1 and 2.2 at the levels A and AA.
const TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"];
// The impacts that fail the check.
const FAILING = new Set(["critical", "serious"]);
// The finding of a page that doesn't show a scheme that its toggle offers.
const UNSHOWN = {
  rule: "color-scheme",
  impact: "serious",
  link: "https://squidfunk.github.io/mkdocs-material/setup/changing-the-colors/#automatic-light-dark-mode",
  elements: ["body"],
};
// The schemes of the reader's system, and the one of Material that each should show.
const SCHEMES = { light: "default", dark: "slate" };
// Pages that are checked at the same time: one for each core, from two to eight.
const PARALLEL = Math.min(8, Math.max(2, availableParallelism()));
// The address of a website without one in its pages.
const NOWHERE = "https://website.test/";
const TYPES = {
  ".avif": "image/avif",
  ".css": "text/css; charset=utf-8",
  ".gif": "image/gif",
  ".html": "text/html; charset=utf-8",
  ".ico": "image/x-icon",
  ".jpeg": "image/jpeg",
  ".jpg": "image/jpeg",
  ".js": "text/javascript; charset=utf-8",
  ".json": "application/json",
  ".mp4": "video/mp4",
  ".png": "image/png",
  ".svg": "image/svg+xml",
  ".ttf": "font/ttf",
  ".txt": "text/plain; charset=utf-8",
  ".webm": "video/webm",
  ".webp": "image/webp",
  ".woff": "font/woff",
  ".woff2": "font/woff2",
  ".xml": "application/xml",
};

/** The pages of the website: every HTML file of the folder outside its assets. */
async function pagesOf(site) {
  const names = await readdir(site, { recursive: true });
  return names
    .map((name) => name.split(path.sep).join("/"))
    .filter((name) => name.endsWith(".html") && !/^(assets|search)\//.test(name))
    .sort();
}

/** The text of the article of a page, without the date of its last change. */
async function textOf(site, page) {
  const html = await readFile(path.join(site, page), "utf-8");
  const article = html.match(/<article\b[^>]*>([\s\S]*?)<\/article>/)?.[1] ?? html;
  return article
    .replace(/<aside class="md-source-file">[\s\S]*?<\/aside>/g, "")
    .replace(/<[^>]+>/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

/** The pages to check, and those left out: a page in the folder of a further
 * language, such as de/roadmap.html, whose text is that of the page of the default
 * language, roadmap.html, because it has no translation yet. The other pages of that
 * language check its header and menu. */
async function chosen(site, pages) {
  const all = new Set(pages);
  const checked = [];
  const same = [];
  for (const page of pages) {
    const further = page.match(/^[a-z]{2}(?:-[A-Za-z]{2,})?\/(.+)$/);
    if (further && all.has(further[1]) &&
      (await textOf(site, page)) === (await textOf(site, further[1]))) {
      same.push(page);
    } else {
      checked.push(page);
    }
  }
  return { checked, same };
}

/** The address of the website, from the canonical link of its home page. */
async function addressOf(site) {
  try {
    const home = await readFile(path.join(site, "index.html"), "utf-8");
    const canonical = home.match(/<link rel="canonical" href="([^"]+)"/);
    if (canonical) {
      return new URL(".", canonical[1]).href;
    }
  } catch {
    // A website without a home page has no address of its own.
  }
  return NOWHERE;
}

/** The Markdown file of a page, for the annotation, when docs/ has one. */
export function sourceOf(page, root = ".") {
  const name = page.replace(/\.html$/, "");
  const candidates = [`${name}.md`, `${name}/index.md`, `${name}/README.md`];
  const language = name.match(/^([a-z]{2})\/(.+)$/);
  if (language) {
    const [, code, rest] = language;
    candidates.push(`${rest}.${code}.md`, `${rest}/index.${code}.md`, `${rest}/README.${code}.md`);
  }
  if (name === "index" || name.endsWith("/index")) {
    candidates.push(`${name.replace(/index$/, "")}README.md`);
  }
  return candidates
    .map((candidate) => `docs/${candidate}`)
    .find((candidate) => existsSync(path.join(root, candidate)));
}

/** A value for a command of GitHub Actions, such as ::error. */
export function escaped(text, property = false) {
  let value = String(text).replaceAll("%", "%25").replaceAll("\r", "%0D").replaceAll("\n", "%0A");
  if (property) {
    value = value.replaceAll(":", "%3A").replaceAll(",", "%2C");
  }
  return value;
}

/** Answers every request to the website from the folder, and refuses the others. */
async function serve(context, site, address, foreign) {
  const root = path.resolve(site);
  const prefix = new URL(address).pathname;
  await context.route("**/*", async (route) => {
    const url = new URL(route.request().url());
    if (!url.href.startsWith(address)) {
      foreign.add(url.origin);
      await route.abort("blockedbyclient");
      return;
    }
    let file = path.resolve(root, decodeURIComponent(url.pathname.slice(prefix.length)));
    if (url.pathname.endsWith("/")) {
      file = path.join(file, "index.html");
    }
    if (file !== root && !file.startsWith(root + path.sep)) {
      await route.fulfill({ status: 403 });
      return;
    }
    try {
      const body = await readFile(file);
      const type = TYPES[path.extname(file).toLowerCase()] ?? "application/octet-stream";
      await route.fulfill({ status: 200, contentType: type, body });
    } catch {
      await route.fulfill({ status: 404, contentType: TYPES[".txt"], body: "Not found" });
    }
  });
}

/** Waits until the page shows a scheme, as Material sets it with its script; a page
 * without the schemes of Material, such as the dashboard, shows each one. */
async function showing(tab, scheme, timeout) {
  try {
    await tab.waitForFunction(
      (wanted) => {
        const body = document.body;
        return !body?.hasAttribute("data-md-color-scheme") ||
          body.getAttribute("data-md-color-scheme") === wanted;
      },
      SCHEMES[scheme],
      { timeout },
    );
    return true;
  } catch {
    return false;
  }
}

/** The violations of a page in one scheme, as it shows now. */
async function violations(tab, page, scheme) {
  // The fonts, and the frames after which the script of Material has measured the
  // page, such as a block of code that scrolls and takes the focus.
  await tab.evaluate(async () => {
    await document.fonts.ready;
    for (let frame = 0; frame < 3; frame++) {
      await new Promise((resolve) => requestAnimationFrame(resolve));
    }
  });
  const results = await new AxeBuilder({ page: tab }).withTags(TAGS).analyze();
  return results.violations.map((violation) => ({
    page,
    scheme,
    rule: violation.id,
    impact: violation.impact ?? "minor",
    help: violation.help,
    link: violation.helpUrl,
    elements: violation.nodes.map((node) => node.target.join(" ")),
  }));
}

/** Whether the page shows a scheme: as the system shows it, which a palette with
 * the media of that scheme follows, or else after the toggle of the website switched
 * to it. Null for a page whose toggle doesn't offer it, such as a website with one
 * scheme alone. */
async function shows(tab, scheme) {
  if (await showing(tab, scheme, 3_000)) {
    return true;
  }
  const offered = await tab.evaluate((wanted) => {
    const option = document.querySelector(`input[name="__palette"][data-md-color-scheme="${wanted}"]`);
    option?.click();
    return option !== null;
  }, SCHEMES[scheme]);
  return offered ? showing(tab, scheme, 3_000) : null;
}

/** The violations of one page in each scheme that the website offers, each in the
 * browser of that scheme. */
async function check(contexts, address, page) {
  const found = [];
  for (const [scheme, context] of Object.entries(contexts)) {
    const tab = await context.newPage();
    try {
      await tab.goto(new URL(page, address).href, { waitUntil: "load", timeout: 60_000 });
      const shown = await shows(tab, scheme);
      if (shown) {
        found.push(...(await violations(tab, page, scheme)));
      } else if (shown === false) {
        const help = `The page doesn't show the ${scheme} scheme that its toggle offers, neither as the system shows it nor after the toggle, so it can't be checked in it`;
        found.push({ ...UNSHOWN, page, scheme, help });
      }
    } finally {
      await tab.close();
    }
  }
  return found;
}

/** A browser for each scheme, whose system shows that scheme and whose pages read
 * the website from the folder; each page loads without what an earlier one saved,
 * such as the scheme that Material keeps. */
async function contextsOf(browser, site, address, foreign) {
  const contexts = {};
  for (const scheme of Object.keys(SCHEMES)) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, colorScheme: scheme });
    await context.addInitScript(() => {
      try {
        localStorage.clear();
      } catch {
        // A frame without storage keeps nothing.
      }
    });
    await serve(context, site, address, foreign);
    contexts[scheme] = context;
  }
  return contexts;
}

/** Runs each task with at most PARALLEL at a time. */
async function pool(tasks) {
  const results = [];
  let next = 0;
  async function worker() {
    while (next < tasks.length) {
      const task = tasks[next++];
      results.push(...(await task()));
    }
  }
  await Promise.all(Array.from({ length: Math.min(PARALLEL, tasks.length) }, worker));
  return results;
}

/** The findings of the same rule on the same page, with the schemes where it fails. */
function grouped(findings) {
  const groups = new Map();
  for (const finding of findings) {
    const key = `${finding.page}\0${finding.rule}`;
    const group = groups.get(key) ?? { ...finding, schemes: [], elements: [] };
    group.schemes.push(finding.scheme);
    for (const element of finding.elements) {
      if (!group.elements.includes(element)) {
        group.elements.push(element);
      }
    }
    groups.set(key, group);
  }
  return [...groups.values()].sort(
    (a, b) => a.page.localeCompare(b.page) || a.rule.localeCompare(b.rule),
  );
}

function described(group) {
  const shown = group.elements.slice(0, 3).map((element) => `\`${element}\``).join(", ");
  const more = group.elements.length > 3 ? ` and ${group.elements.length - 3} more` : "";
  return `${group.help}: ${group.elements.length} element(s) in ${group.schemes.join(" and ")}, ${shown}${more}.`;
}

/** What failed, why it matters and how to fix it, for the summary of the job. */
function summary(failing, lesser, scope, foreign) {
  const lines = ["## Accessibility of the website", ""];
  if (failing.length === 0) {
    lines.push(
      `axe-core found no serious or critical violation of WCAG 2.2 A or AA on ${scope}.`,
    );
  } else {
    lines.push(
      `axe-core found ${failing.length} serious or critical violation(s) of WCAG 2.2 A or AA on ${scope}; the table below names each one.`,
      "",
      "**Why it matters:** a reader who uses a screen reader, the keyboard alone or a larger contrast can't use what these rules find: text too faint to read, a link told apart by its color alone, a control without a name.",
      "",
      "**How to fix it:** the link of each rule explains the fix. A page of `docs/` is yours to fix; what every page shows, such as the header, comes from the blueprint, whose issue tracker takes it. Build the website and run the check again: `npm ci --prefix .github/a11y --ignore-scripts && node .github/a11y/check.mjs site`.",
      "",
      "| Page | Rule | Impact | What it found |",
      "| --- | --- | --- | --- |",
    );
    for (const group of failing) {
      lines.push(
        `| \`${group.page}\` | [${group.rule}](${group.link}) | ${group.impact} | ${described(group).replaceAll("|", "\\|")} |`,
      );
    }
  }
  if (lesser.length > 0) {
    const rules = [...new Set(lesser.map((group) => group.rule))].sort().join(", ");
    lines.push("", `Moderate or minor, which fail nothing: ${lesser.length} on the pages, of the rules ${rules}.`);
  }
  if (foreign.size > 0) {
    lines.push("", `The pages asked other servers, which the check refused: ${[...foreign].sort().join(", ")}.`);
  }
  return `${lines.join("\n")}\n`;
}

/** Starts the Chrome of the runner, or the one that CHROME names. */
export function launch() {
  const executablePath = process.env.CHROME || undefined;
  return chromium.launch(executablePath ? { executablePath } : { channel: "chrome" });
}

/** The findings of the pages of the website in the folder site, or of the pages
 * named alone when there are any: the pages checked, those left out for the page of
 * their default language, the findings and the other servers that the pages asked. */
export async function checkSite(browser, site, named = []) {
  const all = await pagesOf(site);
  const { checked, same } = named.length > 0
    ? { checked: all.filter((page) => named.includes(page)), same: [] }
    : await chosen(site, all);
  const address = await addressOf(site);
  const foreign = new Set();
  const contexts = await contextsOf(browser, site, address, foreign);
  try {
    const findings = await pool(checked.map((page) => () => check(contexts, address, page)));
    return { pages: checked, same, findings, foreign };
  } finally {
    await Promise.all(Object.values(contexts).map((context) => context.close()));
  }
}

async function main(argv) {
  const [site, ...named] = argv;
  if (!site || !existsSync(path.join(site, "index.html"))) {
    console.error("Usage: node .github/a11y/check.mjs SITE [PAGE...]: SITE is the folder of a built website, with its index.html; each PAGE, such as dashboard/index.html, checks that page alone.");
    return 2;
  }
  const missing = named.filter((page) => !existsSync(path.join(site, page)));
  if (missing.length > 0) {
    console.error(`The website has no page ${missing.join(", ")}.`);
    return 2;
  }
  let browser;
  try {
    browser = await launch();
  } catch (error) {
    console.error(`Chrome doesn't start: ${error.message.split("\n")[0]}`);
    console.error("Install Google Chrome, or name another Chrome or Chromium with CHROME=/path/to/chrome.");
    return 2;
  }
  let result;
  try {
    result = await checkSite(browser, site, named);
  } finally {
    await browser.close();
  }
  const { pages, same, findings, foreign } = result;
  const groups = grouped(findings);
  const scope = `${pages.length} page(s) in the light and the dark scheme` + (same.length > 0
    ? `, which stand in for ${same.length} more of another language without a translation`
    : "");
  const failing = groups.filter((group) => FAILING.has(group.impact));
  const lesser = groups.filter((group) => !FAILING.has(group.impact));
  const actions = process.env.GITHUB_ACTIONS === "true";
  // A check that only reports annotates with warnings.
  const level = process.env.REPORT_ONLY === "true" ? "warning" : "error";
  for (const group of failing) {
    const message = `${described(group)} ${group.link}`;
    if (actions) {
      const file = sourceOf(group.page);
      const where = file ? `file=${escaped(file, true)},` : "";
      console.log(`::${level} ${where}title=${escaped(`Accessibility: ${group.rule} on ${group.page}`, true)}::${escaped(message)}`);
    } else {
      console.log(`${group.impact} ${group.rule} on ${group.page}: ${message}`);
    }
  }
  const text = summary(failing, lesser, scope, foreign);
  if (process.env.GITHUB_STEP_SUMMARY) {
    await appendFile(process.env.GITHUB_STEP_SUMMARY, text);
  }
  console.log(
    failing.length === 0
      ? `No serious or critical violation of WCAG 2.2 A or AA on ${scope}; ${lesser.length} lesser one(s).`
      : `${failing.length} serious or critical violation(s) of WCAG 2.2 A or AA on ${scope}.`,
  );
  if (foreign.size > 0) {
    console.log(`The pages asked other servers, which the check refused: ${[...foreign].sort().join(", ")}.`);
  }
  return failing.length === 0 ? 0 : 1;
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  process.exitCode = await main(process.argv.slice(2));
}
