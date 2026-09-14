# Versioning

- SemVer. `lib/Version.php` (`Version::VERSION`) is the single source of truth; `uptimeify_config()` reads it.
- A git tag `vX.Y.Z` must equal `Version::VERSION`; `release.yml` refuses otherwise.
- Every schema change gets a new step in `Store\Schema::upgrade()` keyed by the version that introduces it,
  and bumps `Schema::VERSION`. WHMCS calls `uptimeify_upgrade()` when `_config['version']` changes.
  Exception until the first release: 0.1.0 is unpublished, so its schema is still edited in `Schema::install()`
  (`Schema::VERSION` stays 1) and pre-release installations (sandbox) are installed fresh.
- Never edit a published tag. A wrong release is superseded by the next patch version.

Release steps: bump `Version::VERSION` and `version` in `whmcs.json` (PackagingTest and `release.yml` enforce
it), move `[Unreleased]` to a dated section in `CHANGELOG.md`, commit `chore(release): vX.Y.Z`, tag `vX.Y.Z`, push tag.
