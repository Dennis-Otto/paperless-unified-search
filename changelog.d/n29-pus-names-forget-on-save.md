### Bug fixes

- **A save of the settings shows the names of correspondents, types and tags at once.** When Paperless refused the list of correspondents, types or tags to the account of the API token, the search didn't ask for it again for ten minutes, also after the account got **View** for **Tag** and the settings were saved, in the browser or with `occ paperless_unified_search:setup`. Now a save asks anew, as it does for the users of Paperless, and another token, such as the one of a search account that the settings create, no longer shows the names that the token before it was given.
