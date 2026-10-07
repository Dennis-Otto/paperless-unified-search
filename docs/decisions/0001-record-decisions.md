# Record the decisions that shape the project

- Status: accepted

## Context

Choices such as the platforms that the project supports, the services it talks to or the way it is released outlive the pull requests that made them. Without their reasons, a later change can't tell a choice made on purpose from an accident.

## Options

1. Keep the reasons in the descriptions of pull requests and in issues.
2. Record each decision in a short file in the repository, next to the code it shapes.

## Decision

Option 2: every decision that shapes the project gets a record in `docs/decisions/`, in the form of [the template](0000-template.md). Pull requests and issues link to it.

## Consequences

A pull request that makes or changes such a decision adds or updates its record. The records are part of the documentation and are linted like it.
