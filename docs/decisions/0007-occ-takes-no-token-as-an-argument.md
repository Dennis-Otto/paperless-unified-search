---
description: "Why occ takes the API token of Paperless only from its standard input or an environment variable, why its setup saves only after a test of the connection, and why the setup check of the overview sends no request to Paperless."
---

# Take the token of occ from the standard input or the environment, and check the set-up without asking Paperless

- Status: accepted
- Date: 2026-10-14

## Context

The settings page was the only way to connect the app to Paperless ([#136](https://github.com/Dennis-Otto/paperless-unified-search/issues/136)). That blocks the set-ups that administrators script, with Ansible, Docker or Nextcloud All-in-One, where nobody opens a browser. A command of `occ` needs the API token, and the usual way to pass a value to a command, an argument such as `--token=…`, puts it into the history of the shell and, while the command runs, into the list of processes, which every user of the server can read with `ps`.

Nextcloud's overview of the administration settings runs the setup check of every app each time it opens. A check that asked Paperless would make the overview wait for it, up to the 10 seconds of a request, and send a request to Paperless whenever an administrator opens the overview.

## Options

1. **The token as an option of the command.** Simple, and common in other apps, but it leaks the token to the history of the shell and the list of processes.
2. **The token from the standard input or from an environment variable.** Both stay out of the history and the list of processes; a file, a secret of Docker or the vault of Ansible hands it over without showing it.
3. **The token from a file named by an option.** Safe as well, but the file must be readable by the user of the web server, which often means a copy of the token next to the data of Nextcloud.

For the setup check:

1. **Test the connection on every visit of the overview.** Always current, but slow and a load on Paperless.
2. **Read only what the app stored.** The version of the last answer of Paperless, the settings, and the history of the problems of the search, which every search that fails or works again keeps current.

## Decision

Option 2 for the token, and option 2 for the setup check.

- `occ paperless_unified_search:setup` has no argument or option that takes the token: `--token-stdin` reads the first line of the standard input, and `--token-env=NAME` the environment variable `NAME`. Without either, the stored token stays, but only for the server it was saved for ([decision 0003](0003-bind-the-token-to-its-server.md)). No output of the commands contains it, also not the error that `-v` shows.
- `setup` saves only after Paperless answered at the URL with the token, as **Test connection and save** does, and names what failed with the same messages, so that a script fails with exit code 1 instead of saving settings that can't work. An option that is left out keeps its stored value, so that a script changes one setting without repeating the others.
- The setup check reads the settings, the version that the last answer of Paperless named and the history of the problems of the search, and sends no request. `occ paperless_unified_search:test` and `check` test the connection on demand and store the version of the answer for it.

## Consequences

Scripts set the app up without a browser and without the token in a place where others can read it, and monitoring tests the connection with `occ paperless_unified_search:test`. The overview opens as fast as without the app; it learns of an outage only from the history, that is after a search failed, and of a new version of Paperless after a search or a test. An environment variable is still visible to the processes of the same user and to `root`, which can read the token in Nextcloud anyway.
