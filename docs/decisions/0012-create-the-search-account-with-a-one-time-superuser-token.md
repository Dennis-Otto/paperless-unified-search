---
description: "Why the settings and occ create the read-only search account in Paperless with the token of a superuser that serves this alone and is never stored, logged or answered, and why each change in Paperless only adds permissions."
---

# Create the search account with a token of a superuser that serves only this

- Status: accepted
- Date: 2026-11-08

## Context

Setting up Paperless was the hardest step for a new administrator ([#336](https://github.com/Dennis-Otto/paperless-unified-search/issues/336)). [Configure](../configure.md) walked through about a dozen steps in the web interface of Paperless: a user that is neither superuser nor staff with View for five models, a workflow that lets it view each new document, three bulk dialogs for the documents, correspondents, types and tags that Paperless has, and a sign-in as that user for its token. Most administrators who skip a step paste the token of their own account instead, a superuser, which the settings warn about ([decision 0010](0010-warn-about-what-the-token-may-do.md)) but which still works, and which whoever gets hold of a backup of Nextcloud could then use to change and delete every document.

Paperless can do each of these steps through its API, but only with the token of a superuser: only a superuser may add users, and only an owner or a superuser may change the permissions of a document. Paperless Sync, the companion app, sets up its own account in the same way ([its decision 0027](https://github.com/Dennis-Otto/paperless-sync/blob/main/docs/decisions/0027-the-account-of-the-app-from-one-token-of-a-superuser.md)).

## Options

1. **Keep the steps by hand.** Nothing powerful passes through Nextcloud, but the set-up stays long, and the token of a superuser often ends up stored in the settings anyway.
2. **Ask for the username and the password of an administrator of Paperless.** A password is worth more than a token: it signs in to the web interface, and often to other services as well.
3. **Ask once for the token of a superuser, use it for the set-up alone and forget it.** The settings create the account, get its token and save the settings with that token; the token of the superuser is neither stored, nor logged, nor answered.

For what the set-up changes in Paperless:

1. **Set the permissions of the documents and the names to what the search needs.** Simple, but it would take away what others may view or change.
2. **Only add the search account to the permissions, as the bulk editor does with Merge with existing permissions.** Everyone keeps what they may do.

## Decision

Option 3, with additive permissions only.

- **Create the search account and save** in the settings, below the token, and `occ paperless_unified_search:setup --create-account` take the token of a superuser of Paperless, the command only from its standard input or an environment variable, as every token ([decision 0007](0007-occ-takes-no-token-as-an-argument.md)). The token is a parameter that PHP leaves out of stack traces, no message, answer, log or setting holds it, and its requests go only to the Paperless URL of the form, follow no redirect and allow the local network only as the form does ([decision 0004](0004-local-network-opt-in.md)). Before any request, the URL and the archive account must be valid, so that nothing changes in a Paperless whose settings can't be saved.
- The set-up first checks that the token belongs to a superuser, in `/api/ui_settings/`, and that Paperless has no user `nextcloud-search`, whatever its case: a user of that name stays as it is, since someone may use it. It then adds that user, active, neither superuser nor staff, with only `view_document`, `view_correspondent`, `view_documenttype`, `view_tag` and `view_uisettings`, and a random password of 64 letters and digits that nobody learns, and gets its token from `/api/token/` with that password, without the token of the superuser.
- As the administrator chooses, both on at first: a workflow *Nextcloud search: view new documents*, with the trigger Document Added and an action Assignment that lets the user view the document; and the user added to the permission to view of every document, correspondent, document type and tag that Paperless has, with `set_permissions` and `merge` of the bulk editor, in batches of 500.
- The settings are saved with the token of the user once the test of the connection passes with it, as a save does, so the warnings about the token stay silent. A step that fails once the user exists removes the workflow and the user again, and with the user its permissions; when Paperless refuses that too, the message names the user that remains.
- The log and the audit log of Nextcloud record which administrator created the account.

## Consequences

A new administrator connects Paperless with one token and one button, and the token that the settings keep belongs to an account that may only view. The token of a superuser passes through Nextcloud once, over the connection that the administrator chose; whoever controls the server of Nextcloud at that moment could read it, as they could read the stored token of the search at any time. Correspondents, types and tags that someone adds later in the web interface of Paperless still need the account in the Default View Permissions of that person, which the set-up can't set for others; [Configure](../configure.md) keeps that step, and every other step by hand for administrators who would rather not enter the token of a superuser. The contract tests create the account in Paperless-ngx 2.20 and 3.x and search with its token, and the end-to-end tests go through the button.
