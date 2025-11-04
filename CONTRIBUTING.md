# Contributing to Hitchwiki

Welcome! This repository follows a **do-ocracy** approach, where decisions are made by those who actively contribute and maintain the code. If you're interested in improving the project, feel free to propose changes or start working on issues.

## Development Workflow

We use [trunk-based development](https://trunkbaseddevelopment.com/), meaning all development happens on the main branch. Contributors should:

- Work directly on the main branch or create short-lived feature branches that are merged quickly.
- Commit frequently and keep changes small.
- Ensure compatibility with the existing codebase.

Deployments happen from **release branches**, which are typically tied to specific MediaWiki versions and represent stable releases.

**Note:** This repository does not have CI/CD pipelines, so all testing and validation must be done manually before merging. However, there is little risk, as deployment is fairly rare and manual.

## Repository Structure

This is a **monorepository** that contains:

- Core wiki files in the `wiki/` directory.
- Extensions in the `extensions/` directory (including custom ones specific to this instance).
- Skins in the `skins/` directory (including custom skins).
- Patches, SQL scripts, and tools in their respective directories.

Extensions and skins are included here unless they are designed for reuse across multiple wiki instances, in which case they may be maintained separately.

## How to Contribute

1. Fork the repository and clone it locally.
2. Make your changes on the main branch or a short-lived branch.
3. Test your changes thoroughly (no automated tests are available).
4. Submit a pull request with a clear description of the changes.
5. Wait for review and merge by maintainers.

If you have questions or need help, open an issue or reach out to the community.

Thank you for contributing to Hitchwiki!

## Release and Deployment

Deployments are usually handled manually by maintainers with live server access and contributors need not to worry about this.

Release branches should be created according to MediaWiki version and optional monorepo increment:

- **Naming examples**:
  - `mw-{MW_VERSION}+mono.{increment}`
  - `mw-1.44.2+mono.8`: Includes monorepo changes (extensions, skins, patches, etc.) on top of MediaWiki 1.44.2.
  - `mw-1.44.3`: Pure MediaWiki version update without additional monorepo changes.

Deployments should use `docker-compose.prod.yml` without any local changes. Everything is kept in the repository, untracked files should include a minimal `*.example.ext` file.