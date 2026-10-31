---
description: "Connect Paperless Unified Search to Paperless-ngx: a view-only Paperless account and its API token, the settings, Paperless in the local network, and the archive shared read-only."
---

# Configure

Three things make Paperless documents show in Nextcloud's search: a Paperless account that may view them, whose API token goes into the settings of the app; the documents as files of one Nextcloud account, the archive account; and shares of that archive with the users who may find them. This page sets up all three, once the app is [installed](install.md).

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/setting-up-the-search.dark.svg">
  <img src="images/diagrams/setting-up-the-search.svg" alt="Setting up the search: Install the app. In Paperless-ngx, create an account that may read the documents and an API token for it. In the administration settings of Nextcloud, enter the URL, the token and the archive account, select Paperless runs in my local network when Nextcloud reaches Paperless at such an address, and select Test connection and save. When Paperless answers, the settings are saved; otherwise nothing is saved and the message says what to correct. The archive account shares its archive read-only with the users who may find its documents, and they find them under Paperless documents in Nextcloud's search, with Search connected services switched on.">
</picture>

--8<-- "README.md:how-it-works"

## A Paperless account for the search

The search asks Paperless with the API token of one Paperless account, and Paperless answers with the documents that this account may view, and no others. The account thus sets the limit of what anyone in Nextcloud can find: a document that it can't view never shows, whoever searches. Give it a Paperless account of its own that may only view documents, so that a token that leaks can change nothing. Four steps in Paperless set it up, and the pictures show them in Paperless-ngx 3.

### The account

As an administrator of Paperless, open **Users & Groups** and add a user, such as `nextcloud`, that is neither **Superuser** nor **Admin** (staff). Under **Permissions**, give it **View** for **Document**, **Correspondent**, **DocumentType** and **UISettings**, and nothing else, as the picture of step 1 of *Configuration*, below, shows. The search reads nothing else: it asks for the documents of a term and, of each, only the ID, the title, the date, the IDs of its correspondent and its type, and the excerpt around the term; for the names of these correspondents and types, which the results show; and, when the connection is tested, for what the account may do, which Paperless tells only an account that may view its UI settings. [What each permission gives the search](#what-each-permission-gives-the-search) says what each of them does.

### Its documents

Paperless lets a user view its own documents, those without an owner, and those whose permissions let the user or one of its groups view them. Documents of another owner, such as every document that an administrator uploads, need the permission to view for the account, or for a group that it is in:

- **New documents:** open **Workflows** and add a workflow with the trigger *Document Added* and an action *Assignment* that names the account, or its group, under **Assign view permissions**. Paperless then lets the account view each document as it adds it.
- **The documents that Paperless has:** select them in the list of documents and grant the account the permission to view them with the bulk editor, under **Permissions**, with **Merge with existing permissions** on, which keeps the permissions that they have.

<figure markdown="span">
  ![The dialog Edit workflow of Paperless-ngx: the workflow Let the Nextcloud search view new documents, enabled, with the trigger Document Added and the action Assignment, whose Assign view permissions name the user nextcloud](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/15-paperless-workflow.png#only-light){ width="360" }
  ![The dialog Edit workflow of Paperless-ngx: the workflow Let the Nextcloud search view new documents, enabled, with the trigger Document Added and the action Assignment, whose Assign view permissions name the user nextcloud](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/15-paperless-workflow-dark.png#only-dark){ width="360" }
  <figcaption>A workflow that lets the account view each new document</figcaption>
</figure>

### The names of correspondents and types

The permission to view correspondents and document types works as the one for documents: the account sees the names of those that it owns, that have no owner, and those that it may view. Whoever adds a correspondent or a type in the web interface of Paperless owns it, so until the account may view them, the results and the dashboard show no correspondent and no type. The settings warn about it when the newest documents name one that the account can't see:

- **The correspondents and types that Paperless has:** open **Correspondents**, select all of them, select **Permissions**, add the account under **View**, **Users**, and select **Confirm** with **Merge with existing permissions** on. Then do the same under **Document types**.
- **New ones:** each administrator of Paperless adds the account under **Settings**, **Permissions**, **Default View Permissions**, **Users**. Paperless then lets it view each correspondent and type that they add in the web interface.

<figure markdown="span">
  ![The dialog Set permissions of Paperless-ngx for all selected correspondents: no owner, View for the user nextcloud, no Edit, and Merge with existing permissions switched on](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/16-paperless-names.png#only-light){ width="360" }
  ![The dialog Set permissions of Paperless-ngx for all selected correspondents: no owner, View for the user nextcloud, no Edit, and Merge with existing permissions switched on](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/16-paperless-names-dark.png#only-dark){ width="360" }
  <figcaption>The correspondents that Paperless has, for the account to view</figcaption>
</figure>

### Its token

Sign in to Paperless as the account, open **My Profile** in the menu of the account, and create the token with the button next to **API Auth Token**; the button before it copies the token. An administrator of Paperless can also create it for the account in the Django administration of Paperless, at `/admin/`, under *Auth Token*.

<figure markdown="span">
  ![The dialog Edit Profile of the account nextcloud in Paperless-ngx: its API Auth Token, with the buttons that copy it and that create a new one](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/17-paperless-token.png#only-light){ width="360" }
  ![The dialog Edit Profile of the account nextcloud in Paperless-ngx: its API Auth Token, with the buttons that copy it and that create a new one](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/17-paperless-token-dark.png#only-dark){ width="360" }
  <figcaption>The API token of the account, in My Profile</figcaption>
</figure>

### What each permission gives the search

| Permission of the account | What the search does with it | Without it |
| --- | --- | --- |
| **View** for **Document** | Finds the documents that the account may view, with the words around the term, also those whose note has the term, such as `notes:transfer` | Paperless refuses the token, and the test of the connection fails |
| **View** for **Correspondent** | Shows the correspondent of each result and of each document on the dashboard, when the account may view that correspondent | No correspondent in the results and on the dashboard, and the settings warn |
| **View** for **DocumentType** | Shows the type of each result and of each document on the dashboard, when the account may view that type | No type in the results and on the dashboard, and the settings warn |
| **View** for **UISettings** | Lets the test of the connection ask Paperless what the account may do, for the warnings about the token | No warnings at all, and `occ paperless_unified_search:check` says that Paperless doesn't tell |
| **View** for **Note** | Nothing: the notes of a document match without it | Nothing is missing |
| **Add**, **Change** or **Delete** | Nothing: the search only reads | Nothing is missing; the settings warn about any of them for documents, and about adding or changing users or changing groups, since whoever gets hold of such a token may change documents too |

The contract tests check this table against real Paperless-ngx 2.20 and 3.x every week and in every pull request that changes the app, together with the workflow and the permissions of the steps above.

To see what the search can find at most, sign in to Paperless as the account and search there: the search of Nextcloud finds the same documents, of which each user then sees those whose files they can open. A token of a superuser works as well, but whoever gets hold of it may also change and delete every document: a view-only account is the safer choice. The test of the connection says so: the settings warn below the token about an account that is a superuser or an admin, or may add, change or delete documents, and about one that may not view correspondents or document types, or those of the newest documents that another user owns, and [Troubleshooting](troubleshooting.md#warnings-about-the-api-token) says what to do about each warning.

--8<-- "README.md:configuration"

### When the test of the connection fails

**Test connection and save** saves nothing until Paperless answers, and the message says what failed. [Troubleshooting](troubleshooting.md#when-the-test-of-the-connection-fails) lists every message, with what to do about it.

--8<-- "README.md:languages"

## Share the archive read-only

The archive account decides who finds which document: a user sees a Paperless document when the archive account shares its file with them, alone or with a folder around it. Share read-only, so that nobody changes, moves or deletes a file that the synchronization writes.

1. Sign in to Nextcloud as the archive account, such as `paperless`, and open **Files**.
2. Open the details of the folder of the archive, such as *Paperless*, and there the tab **Sharing**.
3. Enter the user or the group, and set the share to **View only**.

<figure markdown="span">
  ![The tab Sharing of the folder Paperless of the archive account in Files: the folder is shared with Jamie Example, View only](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-share-read-only.png#only-light)
  ![The tab Sharing of the folder Paperless of the archive account in Files: the folder is shared with Jamie Example, View only](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-share-read-only-dark.png#only-dark)
  <figcaption>The archive shared read-only with a user</figcaption>
</figure>

- **A group** gives every member the documents at once, also those who join it later.
- **A subfolder**, such as `Paperless/ACME`, gives someone only the documents in it; Paperless Sync sorts the documents into folders by correspondent, type and year.
- **The archive account** finds every document without a share. An administrator of Nextcloud finds only what is shared with them, as any user does: being an administrator opens no file.
- **Only what a user may only read counts:** a user who may change a file of the archive, or add files to its folder, finds none of the documents in it, since that user could give a file the marker of any document. Below the archive account, the settings show with whom it shares its files, such as *Shared read-only: 1 user, 2 groups.*, and warn about each share that allows changes, and so do `occ paperless_unified_search:check` and the overview of the administration settings.

A script shares through the OCS API of Nextcloud, with an [app password](https://docs.nextcloud.com/server/latest/user_manual/en/session_management.html#managing-devices) of the archive account: `shareType` is `0` for a user and `1` for a group, and `permissions=1` is read-only.

```sh
curl -u paperless -H 'OCS-APIRequest: true' \
  -d path=/Paperless -d shareType=1 -d shareWith=accounting -d permissions=1 \
  'https://cloud.example.com/ocs/v2.php/apps/files_sharing/api/v1/shares'
```

--8<-- "README.md:any-sync"

## Check that it works

1. Sign in as a user with whom the archive is shared, or as the archive account.
2. Open Nextcloud's search and search for a word from the text of a document, one that isn't in its file name.
3. Switch on **Search connected services**: the document shows under **Paperless documents**, and selecting it opens its file.

<figure markdown="span">
  ![The settings after a successful test of the connection: the URL, the stored token, the archive account and the message Connection successful. Settings saved.](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/03-admin-settings.png#only-light)
  ![The settings after a successful test of the connection: the URL, the stored token, the archive account and the message Connection successful. Settings saved.](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/03-admin-settings-dark.png#only-dark)
  <figcaption>The settings after a successful test of the connection</figcaption>
</figure>

No Paperless documents? [Troubleshooting](troubleshooting.md) finds the cause step by step.
