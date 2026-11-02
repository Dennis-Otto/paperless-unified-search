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
// With --write-digest FILE it writes a digest of the website: the sha256 of each page,
// without the date of its last change, and of what every page shares: the stylesheets
// and scripts of the website, mkdocs.yml, the accepted violations and the tools of this
// check. With --changed-since FILE it reads such a digest and checks only the pages that
// differ from it or are new, as the check docs does on a pull request with the digest
// of main; without a digest, or when something that every page shares differs, it
// checks every page.
//
// A violation that .github/a11y-accepted.toml names, by its page and rule and with its
// reason, passes; a line baseline = true in that file makes the run report instead of
// fail, and a run of every page then writes what it found there and takes the line away.
//
// It drives the Google Chrome that GitHub's runners have; CHROME names another
// Chrome or Chromium, by the path of its program. REPORT_ONLY=true annotates the
// violations as warnings rather than errors, for a check that doesn't fail on them.
// Renovate keeps the tools current.
// https://github.com/Dennis-Otto/repo-blueprint

import { createHash } from "node:crypto";
import { existsSync } from "node:fs";
import { appendFile, readdir, readFile, writeFile } from "node:fs/promises";
import { availableParallelism } from "node:os";
import path from "node:path";
import process from "node:process";
import { pathToFileURL } from "node:url";
import { parseArgs } from "node:util";

import { AxeBuilder } from "@axe-core/playwright";
import { chromium } from "playwright-core";
import { parse as parseToml } from "smol-toml";

// The functions that the marks node:coverage leave out of the coverage run in the
// page, in Chrome, where the tests see what they do and Node measures nothing.

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
// The violations that this repository accepts, each with its reason.
const ACCEPTED = ".github/a11y-accepted.toml";
// The line of that file, with the two lines of comment above it, that makes a run
// write what it finds there.
const BASELINE = /^(?:#.*\r?\n){0,2}baseline\s*=\s*true[^\n]*\n/m;
// The reason of what such a run writes.
const BASELINE_REASON = "It was on the website when this check began to fail on violations. Fix it, or say here why it stays.";
// The format of a digest; a digest of another format counts as none.
const DIGEST_FORMAT = 1;
// The date of the last change of a page, which says nothing about its accessibility.
const LAST_CHANGE = /<aside class="md-source-file">[\s\S]*?<\/aside>/g;
// The files of this repository that every page depends on, beside the stylesheets
// and scripts of the website: what builds the pages, what this check accepts, and the
// check with its tools, a new version of which may find more on any page.
const SHARED = ["mkdocs.yml", ".github/mkdocs-blueprint.yml", ".github/a11y/check.mjs", ".github/a11y/package-lock.json", ACCEPTED];
const USAGE = "Usage: node .github/a11y/check.mjs SITE [PAGE...] [--changed-since DIGEST] [--write-digest DIGEST]: SITE is the folder of a built website, with its index.html; each PAGE, such as dashboard/index.html, checks that page alone; --changed-since checks only the pages that differ from a digest, and --write-digest writes the digest of this website.";
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

/** The files of the folder, by their paths with slashes. */
async function namesOf(site) {
  const names = await readdir(site, { recursive: true });
  return names.map((name) => name.split(path.sep).join("/")).sort();
}

/** The pages of the website: every HTML file of the folder outside its assets. */
function pagesAmong(names) {
  return names.filter((name) => name.endsWith(".html") && !/^(assets|search)\//.test(name));
}

function sha256(data) {
  return createHash("sha256").update(data).digest("hex");
}

/** The digest of a website: the sha256 of each page without the date of its last
 * change, and of each file that every page shares. */
export async function digestOf(site, root = ".") {
  const names = await namesOf(site);
  const pages = {};
  for (const page of pagesAmong(names)) {
    pages[page] = sha256((await readFile(path.join(site, page), "utf-8")).replace(LAST_CHANGE, ""));
  }
  const shared = {};
  for (const name of names.filter((name) => /\.(?:css|js)$/.test(name))) {
    shared[`site/${name}`] = sha256(await readFile(path.join(site, name)));
  }
  for (const name of SHARED.filter((name) => existsSync(path.join(root, name)))) {
    shared[name] = sha256(await readFile(path.join(root, name)));
  }
  return { format: DIGEST_FORMAT, shared, pages };
}

/** The digest in a file, or null for a file that is missing or that holds no digest
 * of this format: the digest comes from a cache, which may be empty. */
export async function readDigest(file) {
  try {
    const digest = JSON.parse(await readFile(file, "utf-8"));
    return digest.format === DIGEST_FORMAT && digest.shared && digest.pages ? digest : null;
  } catch {
    return null;
  }
}

/** The pages to check against an older digest: those that differ from it and the new
 * ones; or null with the reason when every page needs the check. */
export function changedPages(digest, before) {
  if (!before) {
    return { pages: null, reason: "there is no digest of main to compare with" };
  }
  const shared = new Set([...Object.keys(digest.shared), ...Object.keys(before.shared)]);
  const differ = [...shared].filter((name) => digest.shared[name] !== before.shared[name]).sort();
  if (differ.length > 0) {
    const more = differ.length > 3 ? ` and ${differ.length - 3} more` : "";
    const named = differ.slice(0, 3).map((name) => `\`${name}\``).join(", ");
    return { pages: null, reason: `${named}${more}, which every page shares, differ from main` };
  }
  const pages = Object.keys(digest.pages).filter((page) => digest.pages[page] !== before.pages[page]);
  return { pages, reason: "" };
}

/** The text of the file of accepted violations, or the empty text for a repository
 * without one. The read alone says whether the file exists, so that the file can't
 * change between a look at it and the read, and the write of the baseline later on
 * follows no such look. */
export async function readAccepted(file) {
  try {
    return await readFile(file, "utf-8");
  } catch (error) {
    if (error.code === "ENOENT") {
      return "";
    }
    throw error;
  }
}

/** What a file of accepted violations says, or an error with what is wrong in it. */
export function parseAccepted(text) {
  let data;
  try {
    data = parseToml(text);
  } catch (error) {
    throw new Error(`${ACCEPTED} isn't valid TOML: ${error.message.split("\n")[0]}`);
  }
  const { baseline = false, accepted = [], ...others } = data;
  const problems = Object.keys(others).map((key) => `the key ${key} means nothing here`);
  if (typeof baseline !== "boolean") {
    problems.push("baseline is true or false");
  }
  const entries = Array.isArray(accepted) ? accepted : [];
  if (!Array.isArray(accepted)) {
    problems.push("accepted is a list of tables, each [[accepted]]");
  }
  entries.forEach((entry, index) => {
    for (const key of ["page", "rule", "reason"]) {
      if (typeof entry[key] !== "string" || entry[key].trim() === "") {
        problems.push(`entry ${index + 1} has no ${key}`);
      }
    }
  });
  if (problems.length > 0) {
    throw new Error(`${ACCEPTED}: ${problems.join("; ")}.`);
  }
  return { baseline, entries };
}

/** The file of accepted violations as a run writes it for what it found. */
export function withBaseline(text, groups) {
  const added = groups.map((group) => [
    "",
    "[[accepted]]",
    `page = ${JSON.stringify(group.page)}`,
    `rule = ${JSON.stringify(group.rule)}`,
    `reason = ${JSON.stringify(BASELINE_REASON)}`,
    "",
  ].join("\n"));
  const kept = text.replace(BASELINE, "").trimEnd();
  return (kept ? `${kept}\n` : "") + added.join("");
}

/** The violations that fail a page: those that nothing accepts, those accepted with
 * their reasons, and the entries of pages that were checked or are gone that found
 * nothing, which are no longer needed. */
export function sorted(groups, entries, checked, existing) {
  const fresh = [];
  const known = [];
  const used = new Set();
  for (const group of groups) {
    const entry = entries.find((entry) => entry.page === group.page && entry.rule === group.rule);
    if (entry) {
      used.add(entry);
      known.push({ ...group, reason: entry.reason });
    } else {
      fresh.push(group);
    }
  }
  const gone = entries.filter((entry) =>
    !used.has(entry) && (checked.includes(entry.page) || !existing.includes(entry.page)));
  return { fresh, known, gone };
}

/** The text of the article of a page, without the date of its last change. */
async function textOf(site, page) {
  const html = await readFile(path.join(site, page), "utf-8");
  const article = html.match(/<article\b[^>]*>([\s\S]*?)<\/article>/)?.[1] ?? html;
  return article
    .replace(LAST_CHANGE, "")
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

/** The annotation of a violation: at the Markdown of its page, or at mkdocs.yml for a
 * page that has none, such as one that a hook writes, since an annotation without a
 * file lands on the folder .github of the workflow. */
export function annotation(group, level, root = ".") {
  const file = sourceOf(group.page, root) ?? "mkdocs.yml";
  const title = `Accessibility: ${group.rule} on ${group.page}`;
  return `::${level} file=${escaped(file, true)},title=${escaped(title, true)}::${escaped(`${described(group)} ${group.link}`)}`;
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
      /* node:coverage disable */
      (wanted) => {
        const body = document.body;
        return !body?.hasAttribute("data-md-color-scheme") ||
          body.getAttribute("data-md-color-scheme") === wanted;
      },
      /* node:coverage enable */
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
  /* node:coverage disable */
  await tab.evaluate(async () => {
    await document.fonts.ready;
    for (let frame = 0; frame < 3; frame++) {
      await new Promise((resolve) => requestAnimationFrame(resolve));
    }
  });
  /* node:coverage enable */
  const results = await new AxeBuilder({ page: tab }).withTags(TAGS).analyze();
  return results.violations.map((violation) => ({
    page,
    scheme,
    rule: violation.id,
    impact: violation.impact,
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
  /* node:coverage disable */
  const offered = await tab.evaluate((wanted) => {
    const option = document.querySelector(`input[name="__palette"][data-md-color-scheme="${wanted}"]`);
    option?.click();
    return option !== null;
  }, SCHEMES[scheme]);
  /* node:coverage enable */
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
    /* node:coverage disable */
    await context.addInitScript(() => {
      try {
        localStorage.clear();
      } catch {
        // A frame without storage keeps nothing.
      }
    });
    /* node:coverage enable */
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
function summary({ fresh, known, gone, lesser, scope, why, foreign, baseline }) {
  const lines = ["## Accessibility of the website", ""];
  if (why) {
    lines.push(`It checked every page, because ${why}.`, "");
  }
  if (baseline) {
    lines.push(
      `\`${ACCEPTED}\` still has its line \`baseline = true\`, so this run fails on nothing. A run of every page on \`main\` writes what it finds there, as the accepted violations, and takes the line away; the fix bot brings that in a pull request.`,
      "",
    );
  }
  if (fresh.length === 0) {
    lines.push(
      `axe-core found no new serious or critical violation of WCAG 2.2 A or AA on ${scope}.`,
    );
  } else {
    lines.push(
      `axe-core found ${fresh.length} serious or critical violation(s) of WCAG 2.2 A or AA on ${scope}; the table below names each one.`,
      "",
      "**Why it matters:** a reader who uses a screen reader, the keyboard alone or a larger contrast can't use what these rules find: text too faint to read, a link told apart by its color alone, a control without a name.",
      "",
      `**How to fix it:** the link of each rule explains the fix. A page of \`docs/\` is yours to fix; what every page shows, such as the header, comes from the blueprint, whose issue tracker takes it. Build the website and run the check again: \`npm ci --prefix .github/a11y --ignore-scripts && node .github/a11y/check.mjs site\`. A violation that has to stay goes into \`${ACCEPTED}\`, with its page, its rule and the reason.`,
      "",
      "| Page | Rule | Impact | What it found |",
      "| --- | --- | --- | --- |",
    );
    for (const group of fresh) {
      lines.push(
        `| \`${group.page}\` | [${group.rule}](${group.link}) | ${group.impact} | ${described(group).replaceAll("|", "\\|")} |`,
      );
    }
  }
  if (known.length > 0) {
    lines.push("", `Accepted in \`${ACCEPTED}\`, which fail nothing: ${known.length}.`, "");
    for (const group of known) {
      lines.push(`- \`${group.page}\`, ${group.rule}: ${group.reason}`);
    }
  }
  if (gone.length > 0) {
    lines.push("", `Accepted in \`${ACCEPTED}\` but no longer found; take these out, so that the violation can't come back unseen:`, "");
    for (const entry of gone) {
      lines.push(`- \`${entry.page}\`, ${entry.rule}`);
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
 * named alone when there are any, and of those among changed alone when it is a list:
 * the pages checked, those left out for the page of their default language, the
 * findings and the other servers that the pages asked. */
export async function checkSite(browser, site, named = [], changed = null) {
  const all = pagesAmong(await namesOf(site));
  const { checked, same } = named.length > 0
    ? { checked: all.filter((page) => named.includes(page)), same: [] }
    : await chosen(site, all);
  const pages = changed ? checked.filter((page) => changed.includes(page)) : checked;
  const address = await addressOf(site);
  const foreign = new Set();
  const contexts = await contextsOf(browser, site, address, foreign);
  try {
    const findings = await pool(pages.map((page) => () => check(contexts, address, page)));
    return { pages, same: changed ? [] : same, findings, foreign };
  } finally {
    await Promise.all(Object.values(contexts).map((context) => context.close()));
  }
}

/** The arguments of the command line, or null when it can't read them. */
function argumentsOf(argv) {
  try {
    const { values, positionals } = parseArgs({
      args: argv,
      allowPositionals: true,
      options: { "changed-since": { type: "string" }, "write-digest": { type: "string" } },
    });
    return { values, site: positionals[0], named: positionals.slice(1) };
  } catch {
    return null;
  }
}

/** The check on the command line, in the folder root of the repository: 0 when it
 * passes, 1 when a violation fails it, and 2 when it can't run. */
export async function main(argv, root = ".") {
  const args = argumentsOf(argv);
  if (!args?.site || !existsSync(path.join(args.site, "index.html"))) {
    console.error(USAGE);
    return 2;
  }
  const { values, site, named } = args;
  const missing = named.filter((page) => !existsSync(path.join(site, page)));
  if (missing.length > 0) {
    console.error(`The website has no page ${missing.join(", ")}.`);
    return 2;
  }
  const acceptedFile = path.join(root, ACCEPTED);
  const acceptedText = await readAccepted(acceptedFile);
  let accepted;
  try {
    accepted = parseAccepted(acceptedText);
  } catch (error) {
    console.error(error.message);
    if (process.env.GITHUB_ACTIONS === "true") {
      const title = escaped("Accessibility: the accepted violations", true);
      console.log(`::error file=${escaped(ACCEPTED, true)},title=${title}::${escaped(error.message)}`);
    }
    if (process.env.GITHUB_STEP_SUMMARY) {
      await appendFile(process.env.GITHUB_STEP_SUMMARY, `## Accessibility of the website\n\nThe check can't read what it accepts, so it stopped before it opened a page.\n\n**The problem:** ${error.message}\n\n**How to fix it:** every \`[[accepted]]\` entry in \`${ACCEPTED}\` has a \`page\`, a \`rule\` and a \`reason\`, and the file holds nothing else than those and the line \`baseline = true\`.\n`);
    }
    return 2;
  }
  const digest = await digestOf(site, root);
  if (values["write-digest"]) {
    await writeFile(values["write-digest"], `${JSON.stringify(digest)}\n`);
  }
  const { pages: changed, reason: why } = values["changed-since"] && named.length === 0
    ? changedPages(digest, await readDigest(values["changed-since"]))
    : { pages: null, reason: "" };
  if (changed?.length === 0) {
    const nothing = "No page differs from the website of main, so the check has nothing to open.";
    if (process.env.GITHUB_STEP_SUMMARY) {
      await appendFile(process.env.GITHUB_STEP_SUMMARY, `## Accessibility of the website\n\n${nothing}\n`);
    }
    console.log(nothing);
    return 0;
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
    result = await checkSite(browser, site, named, changed);
  } finally {
    await browser.close();
  }
  const { pages, same, findings, foreign } = result;
  const groups = grouped(findings);
  const scope = changed
    ? `the ${pages.length} changed page(s) of ${Object.keys(digest.pages).length} in the light and the dark scheme`
    : `${pages.length} page(s) in the light and the dark scheme` + (same.length > 0
      ? `, which stand in for ${same.length} more of another language without a translation`
      : "");
  const failing = groups.filter((group) => FAILING.has(group.impact));
  const lesser = groups.filter((group) => !FAILING.has(group.impact));
  const { fresh, known, gone } = sorted(failing, accepted.entries, pages, Object.keys(digest.pages));
  const actions = process.env.GITHUB_ACTIONS === "true";
  // A check that only reports annotates with warnings.
  const reports = process.env.REPORT_ONLY === "true" || accepted.baseline;
  const level = reports ? "warning" : "error";
  for (const group of fresh) {
    if (actions) {
      console.log(annotation(group, level, root));
    } else {
      console.log(`${group.impact} ${group.rule} on ${group.page}: ${described(group)} ${group.link}`);
    }
  }
  for (const entry of gone) {
    const message = `${ACCEPTED} accepts ${entry.rule} on ${entry.page}, which the check no longer finds: take the entry out.`;
    const title = escaped("Accessibility: an accepted violation is gone", true);
    console.log(actions ? `::warning file=${escaped(ACCEPTED, true)},title=${title}::${escaped(message)}` : message);
  }
  // A run of every page on a website with the line baseline = true accepts what it
  // found. Only such a run: another one hasn't seen what the website has.
  const written = accepted.baseline && named.length === 0 && changed === null;
  if (written) {
    await writeFile(acceptedFile, withBaseline(acceptedText, fresh));
    console.log(`${ACCEPTED} now accepts the ${fresh.length} violation(s) that this run found: commit it. From then on, every other one fails the check.`);
    if (process.env.GITHUB_OUTPUT) {
      await appendFile(process.env.GITHUB_OUTPUT, "written=true\n");
    }
  }
  const text = summary({ fresh, known, gone, lesser, scope, why, foreign, baseline: accepted.baseline });
  if (process.env.GITHUB_STEP_SUMMARY) {
    await appendFile(process.env.GITHUB_STEP_SUMMARY, text);
  }
  if (why) {
    console.log(`It checked every page, because ${why}.`);
  }
  console.log(
    fresh.length === 0
      ? `No new serious or critical violation of WCAG 2.2 A or AA on ${scope}; ${known.length} accepted, ${lesser.length} lesser one(s).`
      : `${fresh.length} serious or critical violation(s) of WCAG 2.2 A or AA on ${scope}.`,
  );
  if (foreign.size > 0) {
    console.log(`The pages asked other servers, which the check refused: ${[...foreign].sort().join(", ")}.`);
  }
  return fresh.length === 0 || reports ? 0 : 1;
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  process.exitCode = await main(process.argv.slice(2));
}
