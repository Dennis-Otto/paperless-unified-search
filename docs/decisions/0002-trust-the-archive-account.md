---
description: "Why a Paperless document appears only through a file that the archive account owns or shares, not through any file with its marker in the name."
---

# Trust the owner of a file, not its name

- Status: accepted
- Date: 2026-10-08

## Context

A Paperless document appears in the search of a user through a Nextcloud file whose name carries its marker `[P<ID>]`. The app asks Paperless with the token of the administrator, so it can find every document. Anyone can give a file such a name, so the name alone can't decide who may see the title and the excerpt of a document.

## Options

1. Keep trusting the name.
2. Count only files of one account, the archive account, which owns the synchronized files; other users see them only through its shares.
3. Check every file against the records of Paperless Sync.
4. Check that the content of the file is the document, by its checksum.
5. Give every user a Paperless token of their own.

## Decision

Option 2. The archive account is the one of the settings, or else the account that Paperless Sync writes the archive with, read from its app configuration. Without an archive account, the search shows no documents.

```mermaid
---
config:
  flowchart:
    wrappingWidth: 400
    nodeSpacing: 30
---
flowchart TB
    accTitle: When a file stands for a document
    accDescr: A file that the user can open has [P412] in its name. Without an archive account, there is no document, and Paperless isn't asked. With one, the owner of the file decides: when the archive account doesn't own the file, it stands for no document, because anyone could name a file so. When the archive account owns it, as its own file or one it shared, the user sees document 412 with its title and its excerpt.

    file["A file that the user can open,<br>with [P412] in its name"] --> archive{"An archive<br>account?"}
    archive -- "no" --> none["No document;<br>Paperless<br>isn't asked"]
    archive -- "yes" --> owner{"Does it own<br>the file?"}
    owner -- "no, anyone could<br>name a file so" --> ignored["The file stands<br>for no document"]
    owner -- "yes, its own file<br>or one it shared" --> shown["The user sees<br>document 412: its<br>title and excerpt"]

    classDef yes fill:#16a34a2e,stroke:#16a34a,stroke-width:2px
    classDef no fill:#dc26262e,stroke:#dc2626,stroke-width:2px
    class shown yes
    class none,ignored no
```

## Consequences

Nextcloud's shares stay the only way to give access, as for the files themselves, and the apps stay independent of each other's releases: option 3 would tie the app to the database of Paperless Sync, option 4 would read every file and ask Paperless once more for each result, and option 5 would need a Paperless account for every user. Whoever may write into a folder of the archive account can still give a file of that account the marker of any document, so the archive should be shared read-only.
