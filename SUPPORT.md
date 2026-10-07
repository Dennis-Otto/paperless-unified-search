# Support

Use GitHub issues for reproducible bugs, compatibility reports, and focused feature requests. Include the app version, Nextcloud version, relevant sanitized logs, expected behavior, and reproduction steps.

This community project does not provide guaranteed response times or private operational support. Never post API tokens, credentials, private documents, production URLs, or personal data.

Suspected vulnerabilities must not be reported in a public issue. Follow `SECURITY.md` instead.

## What happens with your issue

Within a few minutes, the repository's issue assistant labels a new issue and posts a first analysis: a summary, the likely cause or the documentation that helps, related issues and, if needed, questions. The assistant currently uses Claude, an AI by Anthropic; it reads the text of the issue and the public repository and can be wrong. The maintainer reads every issue and decides. The assistant is [its own open-source project](https://github.com/Dennis-Otto/issue-assistant).

- **Questions** mark the issue as waiting for you. Answer in a comment or by editing the issue. Without an answer, a reminder follows after 15 days and the issue closes after 30 days; answering reopens it.
- **A likely duplicate** of an open issue gets a notice and closes 3 days later, so the conversation stays in one place. If it's something different, write a comment or react to the notice with 👎, and it stays open.
- **A fix** on `main` marks the issue `fixed-in-next-release`. It stays open until a release ships the fix to the Nextcloud App Store, and then closes with a link to the release. If the problem persists after the update, write a comment within 30 days and the issue reopens.
