# Architecture: conflicts

## Purpose

A Shopware 6 Composer metapackage that declares known-incompatible package versions. When installed as a dependency, it causes Composer to reject installations of conflicting packages or versions that have known incompatibilities with Shopware 6.

## Directory Structure

```
composer.json            — Sole source of truth: lists all conflicting package + version combinations
composer.*.json          — Per-version snapshots tracking conflict history over time (used for release automation)
bin/
  generate-local-repository.php   — Script to generate a local Composer repository from the versioned JSON files
```

No PHP application source code — this is a pure Composer metapackage.

## Key Design Decisions

- **Metapackage type** — `"type": "metapackage"` means Composer installs no files; it only enforces the declared `conflict` constraints.
- **Version-history tracking** — each `composer.{version}.json` records the conflicts active at that Shopware release, enabling reproducible audits.
- **Proactive conflict detection** — when a Shopware update adds a new conflict entry, Composer will refuse to install the incompatible package version during `composer update`, surfacing the problem before deployment.

## Extension Points

None — to add a new conflict, edit `composer.json` and cut a new release.
