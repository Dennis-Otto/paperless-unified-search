# Docker end-to-end tests

The suite starts Nextcloud with this checkout mounted read-only as a Custom App; locally the image of `compose.yaml`, in the CI the current release of every version from `min-version` to `max-version` in `appinfo/info.xml` (set `NEXTCLOUD_IMAGE` to choose one, such as `nextcloud:35-apache`). A deterministic local HTTP service emulates the small Paperless API surface used by the app.

Run:

```bash
bash tests/e2e/run.sh
```

Optional environment variables:

- `E2E_PORT`: host port for Nextcloud, default `18082`
- `DOCKER_BIN`: Docker CLI path, default `docker`
- `KEEP_E2E=1`: keep containers and the test volume running after the suite

All credentials and document data are synthetic, local to the disposable Compose project, and intentionally unsuitable for production.
