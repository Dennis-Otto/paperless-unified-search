# Try Paperless Unified Search in three minutes

One command starts a real Paperless-ngx and a real Nextcloud in Docker on your own machine, with five synthetic documents, [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) and this app set up, and ends with a search that finds the documents. Your own Nextcloud and Paperless stay untouched, and so does every other container.

```sh
git clone https://github.com/Dennis-Otto/paperless-unified-search.git
cd paperless-unified-search
bash demo/seed.sh
```

![The demo at work, as jamie: the archive that Paperless Sync wrote into Nextcloud, a search for "invoice", and below the files the Paperless documents, the two invoices and the home insurance renewal, whose text says "invoice". The renewal opens in Nextcloud's viewer.](../screenshots/demo-search-and-open.gif)

<sub>Recorded from this demo by `bash scripts/screenshots.sh --demo`: [record it again](#record-the-animation).</sub>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="../docs/images/diagrams/what-the-demo-sets-up.dark.svg">
  <img src="../docs/images/diagrams/what-the-demo-sets-up.svg" alt="What the demo sets up: You run bash demo/seed.sh. It generates the secret key of Paperless and the passwords of the demo into demo/.env, and starts Paperless-ngx with Redis and Nextcloud with its background jobs in Docker, on 127.0.0.1 alone. Paperless reads five synthetic letters. Paperless Sync, a pinned signed release, writes each one into the files of the archive account paperless, which shares the archive read-only with jamie. occ paperless_unified_search:setup connects the app to Paperless, with the API token through a pipe. A search as jamie for invoice shows three Paperless documents, and the script prints the addresses and the accounts to sign in with.">
</picture>

## What it needs

- **Docker with Compose**, such as Docker Desktop on Windows and macOS or Docker Engine on Linux, and about 3 GB of disk for the images of Nextcloud and Paperless-ngx.
- **Bash**: the shell of Linux and macOS, or Git Bash on Windows.
- **The ports 8180 and 8100** free on 127.0.0.1, for Nextcloud and Paperless. Other ones go into the environment before the first run, such as `NEXTCLOUD_PORT=9180 PAPERLESS_PORT=9100 bash demo/seed.sh`, and stay in `demo/.env`.
- **The internet**, for the images and for the release of Paperless Sync on GitHub.

The first run downloads the images, a few minutes on most connections; after that, the demo is ready in less than three minutes, most of them the first start of Paperless and Nextcloud.

## What the script does

`seed.sh` runs every step in the containers, so it needs nothing but Docker and Bash, and every step may run again: a second run starts what stopped and adds what is missing.

1. It generates the secret key of Paperless and the passwords of the demo into `demo/.env`, which git ignores, and keeps them for later runs.
2. It starts Paperless-ngx 3 with Redis, and Nextcloud 33 with PostgreSQL and its background jobs, from [`compose.yaml`](compose.yaml). Both listen on 127.0.0.1 alone, so nobody else on your network reaches them.
3. It creates the archive account `paperless` and the user `jamie` in Nextcloud, installs a pinned release of Paperless Sync, whose SHA-256 it checks, and enables this app from your checkout.
4. It gives Paperless the five synthetic letters of [`documents/`](documents) as PDF files, each with its correspondent, type and tags, and waits until Paperless has read and indexed them.
5. It sets up Paperless Sync, which writes every document into `Documents/Paperless/Archive` of `paperless`, and shares that folder read-only with `jamie`.
6. It connects this app to Paperless with `occ paperless_unified_search:setup`, as a script on a server would: the API token goes through a pipe, never on a command line.
7. It checks the whole set-up with `occ paperless_unified_search:check`, searches for *invoice* as `jamie`, and prints the addresses and the accounts:

```console
$ bash demo/seed.sh
…
== 136 s: Check the set-up, and search as jamie
✓ Paperless 3.3.1 (API 10) answers at http://paperless:8000.
✓ The archive account is paperless.
✓ Coverage: 5 of 5 Paperless document(s) have a file of the archive account.
+----+-----------------------------------+---------+---------------------------------------------------------------------------------------------+
| ID | Title                             | Verdict | File                                                                                        |
+----+-----------------------------------+---------+---------------------------------------------------------------------------------------------+
| 2  | Electricity invoice - August 2026 | shown   | /Archive/Northstar Energy/Bill/2026/2026-08-12 - Electricity invoice - August 2026 [P2].pdf |
| 4  | Internet invoice - July 2026      | shown   | /Archive/Fiberlink/Bill/2026/2026-07-03 - Internet invoice - July 2026 [P4].pdf             |
| 3  | Home insurance renewal            | shown   | /Archive/Harbor Mutual/Insurance/2026/2026-07-21 - Home insurance renewal [P3].pdf          |
+----+-----------------------------------+---------+---------------------------------------------------------------------------------------------+
Paperless found 3 document(s) for "invoice"; 3 of the 3 above show to jamie.

== The demo is ready after 143 s.

Nextcloud:  http://localhost:8180
  jamie   …   finds the documents: search for invoice, warranty or Northstar
  admin   …   the administrator: Administration settings, Paperless Unified Search
Paperless:  http://localhost:8100
  admin   …   add a document, and it is in Nextcloud within five minutes
```

## What to try

The passwords are in `demo/.env`, and the script prints them at its end.

1. **Search as jamie.** Open <http://localhost:8180>, sign in as `jamie`, select the search at the top and type *invoice*. Below the files, **Paperless documents** shows three letters: the two invoices, and the home insurance renewal, whose text says "invoice" while its title doesn't. Select one, and it opens in Nextcloud's viewer.
2. **Search like in Paperless.** The query syntax of Paperless works in Nextcloud's search: `correspondent:fiberlink`, `type:receipt`, `tag:household` or `created:[2026-07-01 to 2026-07-31]`, and Nextcloud's own filter of dates narrows the results too.
3. **Link a document.** In a text of Nextcloud Text, type `/` and choose **Paperless documents**: the smart picker finds them by their text as well.
4. **See who sees what.** Sign in as `admin`: the search shows no Paperless documents, because `paperless` shares its archive with `jamie` alone. `occ` says why, document by document:

    ```sh
    docker compose -f demo/compose.yaml exec -T --user www-data nextcloud \
      php occ paperless_unified_search:search admin invoice --explain
    ```

5. **Add a document.** Open <http://localhost:8100>, sign in to Paperless as `admin` and upload a PDF of your own, or a synthetic one. The background jobs of Nextcloud run Paperless Sync every five minutes, which writes it into the archive, and `jamie` finds it by its text.
6. **Look at the settings.** As `admin` in Nextcloud, open **Administration settings → Paperless Unified Search**: the connection that `occ` saved, and **Try a search**, which shows for any user which documents the search shows and why the others don't.

## Stop, start and remove it

```sh
docker compose -f demo/compose.yaml stop                # stop it; the data stays
bash demo/seed.sh                                       # start it again
docker compose -f demo/compose.yaml down --volumes      # remove it with all its data
rm demo/.env                                            # and its passwords
```

The containers start again with Docker, until you stop or remove them. A new run after `down --volumes` keeps the passwords of `demo/.env`; delete it as well for new ones.

## Record the animation

The animation at the top and in the README comes from this demo, recorded by a script, so that it shows what the demo shows:

```sh
bash demo/seed.sh                     # the demo must run
bash scripts/screenshots.sh --demo    # writes screenshots/demo-search-and-open.gif
```

Chromium in the image of Playwright joins the network of the demo, signs in as `jamie` with the password of `demo/.env`, which reaches the container in its environment alone, opens the archive, searches for *invoice* and opens the home insurance renewal. The script writes the animation alone: the demo keeps running, with its data, and the other pictures stay as they are. It fails, with the way to a fresh demo, when the search shows other Paperless documents than the three of the demo, such as one that you added.

Two runs record the same pixels, so the animation in the repository changes only when the demo looks different:

- **The clock** of the browser starts two days after the last change of the archive, so Files always shows *2 days ago*, whenever the demo was set up.
- **The search** starts once the whole term is typed: the clock stands still while the letters go in, so a real Paperless that answers a part of the term sooner in one run than in another doesn't show.
- **What a person does at their first sign-in** happens first: the welcome wizard of Nextcloud is closed, and `jamie` is online, so the dot on the avatar is there from the first frame. The two templates that the search finds, `Invoice.ods` and `Invoice.odt`, which Nextcloud copies in the same second, get times an hour apart, so the search lists them in the same order.

The weekly run of the demo (`.github/workflows/demo.yml`) records the animation as well and keeps it as the artifact `demo-animation` of the run; its summary says when it looks different from the one in the repository.

## A demo, not a production set-up

The demo shows the app at work with the least that it takes, and leaves out what a server needs:

- **One account of Paperless for both apps.** Paperless Sync and this app use the API token of the administrator of Paperless. On a server, give the search [an account that may only read](../docs/configure.md#a-paperless-account-for-the-search), and Paperless Sync one of its own.
- **HTTP on 127.0.0.1, without TLS,** which suits one person trying it on their own machine. [Install](../docs/install.md) sets the app up on a server.
- **The app from your checkout.** Nextcloud runs the code of the working copy, read-only, so a change of the code shows at the next request. To try a release, check out its tag first, such as `git checkout v<version>`, and run `bash demo/seed.sh` again, which upgrades the app in Nextcloud. Only the releases that include `demo/` have the demo, and Nextcloud doesn't go back to an older version of an app: before an older release, remove the demo with `docker compose -f demo/compose.yaml down --volumes`.

The secrets of the demo come from your machine alone: `seed.sh` generates them into `demo/.env`, and git ignores that file. The five documents are synthetic letters of invented companies, and `seed.py` turns the text files of [`documents/`](documents) into their PDF files.

## When it doesn't start

- **A port is taken:** another program listens on 8180 or 8100. Set other ports in `demo/.env`, `NEXTCLOUD_PORT` and `PAPERLESS_PORT`, and run `bash demo/seed.sh` again.
- **"An earlier demo left its data":** `demo/.env` is gone, but the data of a demo with other passwords is still there. `docker compose --project-name paperless-unified-search-demo down --volumes` removes it without `demo/.env`, and the next run starts anew.
- **It stops at a step:** the script shows the end of the logs of the containers and stops; run it again to continue. `docker compose -f demo/compose.yaml logs paperless` and `… logs nextcloud` show the whole logs.

The [troubleshooting guide](https://dennis-otto.github.io/paperless-unified-search/troubleshooting.html) explains every message of the app, and the [discussions](https://github.com/Dennis-Otto/paperless-unified-search/discussions) answer questions.
