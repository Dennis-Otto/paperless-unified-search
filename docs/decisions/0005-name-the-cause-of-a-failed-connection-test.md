---
description: "Why a failed test of the connection names its cause, such as a refused token or an untrusted certificate, with a code and a message in the language of the administrator, and why every message of the settings is translated into informal and formal German."
---

# Name the cause of a failed test of the connection, in the language of the administrator

- Status: accepted
- Date: 2026-10-13

## Context

Saving the settings tests the connection to Paperless first. Until now, the settings answered every failure with the same English sentence, "Could not connect to Paperless. Check the URL, API token, and Nextcloud outbound connection policy.", and logged nothing ([#120](https://github.com/Dennis-Otto/paperless-unified-search/issues/120)): a refused token, a wrong path, the login page of a proxy, a name that doesn't resolve, a refused connection, an untrusted certificate and a local address that Nextcloud refuses all looked alike. Each of them needs another correction, and administrators whose hoster keeps the log of Nextcloud from them had nothing else to go by.

The messages of the server bypassed the translations, so a German administrator saw English errors on a German page. And accounts set to *Deutsch (Förmlich: Sie)* have the language `de_DE`, which the app didn't bring: Nextcloud then falls back to the language of the browser and then to English, so these administrators saw the whole app in English.

## Options

1. Keep one message, and log the error. Nothing to translate, but the administrator still has to find the log, which some can't.
2. Show the message of the error itself, such as `cURL error 60: SSL certificate problem`. Exact, but English, technical, and it shows whatever a server at the URL answers.
3. Name the cause with a code from a fixed list, and show a translated message for each code that says what to correct; log the error itself.

## Decision

Option 3. The Paperless client classifies every failure of the test into a reason: the status of the answer (401 and 403, 404, a redirect, any other), an answer that isn't a list of documents, and, without an answer, a local address that Nextcloud refuses or the error of cURL (a host that doesn't resolve, a timeout, a certificate that isn't trusted, an encryption that fails), and else a connection that failed. A setting that can't be saved has a code of its own as well, such as `invalid_url`. The settings answer the code and a message in the language of the administrator, and log a warning with the error, without the token and the query of the URL.

Every text that the server or the browser shows is translated into German twice: `de` with *du*, as before, and `de_DE` with *Sie*. `scripts/check-l10n.mjs` reads the texts from the calls of `t()` in the code and fails when a catalog lacks one, keeps one that no code uses or changes its placeholders, so a new message can't reach a release untranslated. So that the check finds them, every text stands in the code literally.

## Consequences

An administrator sees what to correct without the log, and the README lists every message with its cause. With *Paperless runs in my local network* selected, the test of the connection tells a little more about an address of the network than success or failure: whether its name resolves, whether something listens there, and with which status it answers, but never the content of the answer. Whoever can save the settings is an administrator of Nextcloud, who can find out more than that anyway; [the security design](../security.md#residual-risks) names it. Each new message needs its two German translations in the same pull request.
