# Docker end-to-end tests

The suite starts Nextcloud with this checkout mounted read-only as a Custom App; locally the image of `compose.yaml`, in the CI the current release of every version from `min-version` to `max-version` in `appinfo/info.xml` (set `NEXTCLOUD_IMAGE` to choose one, such as `nextcloud:35-apache`), and every week [the coming Nextcloud](#the-coming-nextcloud). A deterministic local HTTP service emulates the small Paperless API surface used by the app.

Run:

```bash
bash tests/e2e/run.sh
```

Optional environment variables:

- `E2E_PORT`: host port for Nextcloud, default `18082`
- `DOCKER_BIN`: Docker CLI path, default `docker`
- `E2E_PROJECT_NAME`: Compose project name, default `paperless_unified_search_e2e`
- `KEEP_E2E=1`: keep containers and the test volume running after the suite
- `E2E_IGNORE_MAX_VERSION=1`: enable the app with `--force` on a Nextcloud newer than `max-version`, as `canary.sh` does

All credentials and document data are synthetic, local to the disposable Compose project, and intentionally unsuitable for production.

## Accessibility

At the end of the suite, `accessibility.mjs` checks the administration settings of the app in Chromium with [axe-core](https://github.com/dequelabs/axe-core) against WCAG 2.1 at levels A and AA, in the light and the dark theme of Nextcloud, once as loaded and once with the message after saving. It signs in through the login form and looks only into `#paperless-unified-search-settings`, the element that holds the markup of `templates/`, `js/` and `css/`, so that what Nextcloud draws around it doesn't count. A serious or critical violation fails the suite; the others are listed in the log.

The results in the global search of Nextcloud are left out: Nextcloud draws them, and the app only gives the title, the line below it and the icon. axe-core does find a serious violation there, *nested-interactive*, but every search provider has it, because Nextcloud puts the link of each result into an option of a list box; only Nextcloud can fix it.

The browser runs in the image of Playwright that `run.sh` names, inside the network of the Compose project, and reaches Nextcloud as `http://nextcloud`; the suite turns off the first-run wizard of Nextcloud, which would cover the pages. `package.json` and `package-lock.json` pin axe-core and playwright-core. Keep playwright-core at the version of the image; `scripts/check-project.sh` compares them.

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
