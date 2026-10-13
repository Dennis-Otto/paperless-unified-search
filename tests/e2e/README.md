# Docker end-to-end tests

The suite starts Nextcloud with this checkout mounted read-only as a Custom App: `run.sh` the image of `compose.yaml` (set `NEXTCLOUD_IMAGE` to choose another, such as `nextcloud:35-apache`), [`run-all.sh`](#every-supported-version-at-once) and the CI the current release of every version from `min-version` to `max-version` in `appinfo/info.xml` at the same time, and every week also [the coming Nextcloud](#the-coming-nextcloud). A deterministic local HTTP service emulates the small Paperless API surface used by the app.

```mermaid
flowchart LR
    run["run.sh"]
    subgraph compose["Compose project"]
        nextcloud["Nextcloud<br/>the checkout as Custom App, read-only"]
        mock["Paperless mock<br/>mock_server.py, also as other-host"]
        browser["Chromium of Playwright<br/>accessibility.mjs with axe-core"]
    end
    run -- "occ, OCS, WebDAV<br/>and the routes of the settings" --> nextcloud
    run -- "starts" --> browser
    nextcloud -- "searches" --> mock
    browser -- "opens the settings" --> nextcloud
```

Besides the search, the suite checks the guarantees of the settings: every route of the settings answers a user who isn't an administrator with HTTP 403 and a request without a login with 401, and leaves the settings as they are; a save with a blank token for another server answers 400 and sends that server nothing, which the log of the mock shows, as it answers as `other-host` too; and an archive account typed in another case is stored as Nextcloud spells it.

Run:

```bash
bash tests/e2e/run.sh       # one version: the image of compose.yaml
bash tests/e2e/run-all.sh   # every supported version at the same time, as the CI does
```

Optional environment variables:

- `E2E_PORT`: host port for Nextcloud, default `18082`
- `DOCKER_BIN`: Docker CLI path, default `docker`
- `E2E_PROJECT_NAME`: Compose project name, default `paperless_unified_search_e2e`
- `NEXTCLOUD_IMAGE`: the image of Nextcloud, default the release that `compose.yaml` pins
- `KEEP_E2E=1`: keep containers and the test volume running after the suite
- `E2E_IGNORE_MAX_VERSION=1`: enable the app with `--force` on a Nextcloud newer than `max-version`, as `canary.sh` does
- `E2E_SKIP_PLAYWRIGHT_PULL=1`: leave the download of the image of Playwright to the caller, as `run-all.sh` does

`run.sh` downloads the image of Playwright in the background while Nextcloud sets itself up, removes the containers without waiting for them to stop (and leaves them on a runner of GitHub, which is thrown away after the job anyway), and turns off Nextcloud's password policy, which would check every new password against [Have I Been Pwned](https://haveibeenpwned.com/Passwords), so that no test depends on a service outside the machine. Each call of `occ` starts PHP and Nextcloud anew, which takes a few seconds while three versions run at once; so `occ` only installs Nextcloud, trusts its name in the network and enables the app, the variable `NC_allow_local_remote_servers` of `compose.yaml` lets the app reach the mock, and the running web server turns off the password policy and creates the users. The web server has to do that itself: it caches the settings of the apps for three seconds and would go on checking passwords right after `occ` turned the policy off.

All credentials and document data are synthetic, local to the disposable Compose project, and intentionally unsuitable for production.

## Every supported version at once

`run-all.sh` reads `min-version` and `max-version` of `appinfo/info.xml` and starts `run.sh` for every major version from the one to the other at the same time, each with the image `nextcloud:<version>-apache`, the Compose project `pus_e2e_<version>` and the port `18000 + <version>`, such as `18035` for Nextcloud 35. Each version writes its log to a file of its own; the script notes each version as it finishes and then prints the logs one after another, in the CI each in a group named after the version and its result. The script fails when one version does, and Ctrl-C stops every version and removes its containers. A version that takes longer than `E2E_VERSION_TIMEOUT` seconds, 600 by default and about four times what it needs, stops and fails as well, with its log up to that point and the state of its containers, long before the job of the CI times out.

```bash
bash tests/e2e/run-all.sh                       # every version from min-version to max-version
E2E_VERSIONS="33 35" bash tests/e2e/run-all.sh  # only Nextcloud 33 and 35
```

On a runner of GitHub, with four CPUs and 16 GB of memory, three versions at once take about two and a half minutes and one alone about a minute and a half. The script first downloads the images of Nextcloud and of the mock for every version, and the image of Playwright only while Nextcloud sets itself up, which is faster than downloading them all at once.

## In the CI

The workflow *Docker E2E* (`.github/workflows/e2e.yml`) runs `run-all.sh` in one job, the required check `nextcloud`. A pull request or a push to `main` that changes no file the tests cover passes the check in seconds without running them, with a notice that says so; the job stays and passes, so that the required check reports success. `scope.sh` decides it, and `selftest.sh`, which `scripts/check.sh` runs, tests it with real merge commits and pushes in throwaway Git repositories, and `run-all.sh` with a fake Docker. The tests cover:

- the app: `appinfo/`, `lib/`, `templates/`, `js/`, `css/`, `img/` and `l10n/`
- `composer.json` and `composer.lock`
- the tests themselves, `tests/e2e/`
- the workflow `.github/workflows/e2e.yml`

The weekly run on Mondays and a run started by hand always test every version, so that the newest release behind each image `nextcloud:<version>-apache` is tested even in a week without a change of the app.

```mermaid
---
config:
  flowchart:
    wrappingWidth: 400
---
flowchart TB
    accTitle: The required check nextcloud
    accDescr: A pull request or a push to main that changes no file the end-to-end tests cover passes the check nextcloud in seconds, with a notice. Otherwise, and in the weekly run and a run started by hand, run-all.sh tests every Nextcloud version from min-version to max-version at the same time in one job, and the check passes only when every version passes; the log of each version is a group of its own.

    change["Pull request<br>or push to <code>main</code>"]
    timed["Weekly run<br>or a start by hand"]
    scope{"A file the tests<br>cover changed?"}
    skipped["<code>nextcloud</code> passes in seconds<br>with a notice"]
    all["<code>run-all.sh</code><br>one download of the browser"]
    low["Nextcloud <i>min-version</i>"]
    middle["every version<br>in between"]
    high["Nextcloud <i>max-version</i>"]
    every{"Every version<br>passes?"}
    passed["<code>nextcloud</code> passes"]
    failed["<code>nextcloud</code> fails<br>the log of each version<br>in a group of its own"]

    change --> scope
    scope -- "no" --> skipped
    scope -- "yes" --> all
    timed --> all
    all --> low & middle & high --> every
    every -- "yes" --> passed
    every -- "no" --> failed

    classDef person fill:#f59e0b2e,stroke:#f59e0b,stroke-width:2px
    classDef bot fill:#526cfe2e,stroke:#526cfe,stroke-width:2px
    classDef done fill:#16a34a2e,stroke:#16a34a,stroke-width:2px
    classDef fail fill:#dc26262e,stroke:#dc2626,stroke-width:2px
    class change person
    class timed,all,low,middle,high bot
    class skipped,passed done
    class failed fail
```

## Accessibility

At the end of the suite, `accessibility.mjs` checks the administration settings of the app in Chromium with [axe-core](https://github.com/dequelabs/axe-core) against WCAG 2.1 at levels A and AA, in the light and the dark theme of Nextcloud, in three states: with the warning about an archive account that doesn't exist, which `run.sh` stores before; with the URL of another server, for which the token field is required; and with the message after saving. It signs in through the login form and looks only into `#paperless-unified-search-settings`, the element that holds the markup of `templates/`, `js/` and `css/`, so that what Nextcloud draws around it doesn't count. A serious or critical violation fails the suite; the others are listed in the log.

On the way to these states, the script checks what the settings page does in the browser, and a state that differs fails the suite too. The token field asks for the token again for another scheme, host or port, but not for another path of the stored server. Saving another server with its token makes it the stored URL, after which the mock's own name is the other server; and an archive account that exists ends the warning. `run.sh` then checks that the settings are stored as the browser saved them.

The results in the global search of Nextcloud are left out: Nextcloud draws them, and the app only gives the title, the line below it and the icon. axe-core does find a serious violation there, *nested-interactive*, but every search provider has it, because Nextcloud puts the link of each result into an option of a list box; only Nextcloud can fix it.

The browser runs in the image of Playwright that `run.sh` names, inside the network of the Compose project, and reaches Nextcloud as `http://nextcloud`; the suite turns off the first-run wizard of Nextcloud, which would cover the pages. `package.json` and `package-lock.json` pin axe-core and playwright-core. Keep playwright-core at the version of the image: Renovate updates both together (`.github/renovate.json5`), and `scripts/check-project.sh` compares them.

## Screenshots

`scripts/screenshots.sh` takes the screenshots and the animation in `screenshots/` with the containers of this suite: Nextcloud and the mock under the Compose project `paperless_unified_search_screenshots`, on port `18083`, and `screenshots.mjs` in the image of Playwright. It writes the synthetic documents of the demo as PDF files into the archive account `paperless`, in folders as Paperless Sync names them, and shares them read-only with `jamie`. For the term `invoice`, the mock returns three of them, with the IDs of their files. `pngjs` and `gifenc` in `package.json` read the screenshots and write the animation.

## The coming Nextcloud

Every Monday, and when started by hand, the workflow also runs the suite against the coming Nextcloud:

```bash
bash tests/e2e/canary.sh
```

The coming Nextcloud is the newest beta or release candidate of the next major version while Nextcloud publishes one, in `https://download.nextcloud.com/server/prereleases/`, and otherwise the daily build of its master branch, `https://download.nextcloud.com/server/daily/latest-master.tar.bz2`. Nextcloud no longer publishes Docker images of betas and release candidates, so the script checks the signature of the package against the release key of Nextcloud, as the official image does, and puts its code into the image of the newest release, `nextcloud:apache`, which keeps its PHP, Apache and start script. The suite enables the app with `--force`, as `max-version` of `appinfo/info.xml` doesn't name that version yet; the upstream bot raises `max-version` once Nextcloud releases the version.

The canary is no required check and blocks nothing. When it fails, it opens the issue *The coming Nextcloud breaks the app* with a link to the run, adds every further failed run to it, and closes it once the tests pass again.

Optional environment variables, besides those of `run.sh`:

- `CANARY_BASE_IMAGE`: the image whose code the coming Nextcloud replaces, default `nextcloud:apache`
- `CANARY_IMAGE`: the local image the script builds, default `paperless-unified-search-e2e:coming`
