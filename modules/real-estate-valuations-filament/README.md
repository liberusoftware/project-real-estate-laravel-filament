# Real Estate Valuations Filament

Filament 5 adapter for valuation workflows. Composer uses the unprefixed package name; the GitHub source uses `module-real-estate-valuations-filament`.

<!-- module-presentation-guide:begin -->
## Presentation architecture and project hosts

- Capability/source key: `liberusoftware/real-estate-valuations`.
- Actual Composer identity: `liberusoftware/real-estate-valuations-filament`.
- [Filament capability plan](https://github.com/liberusoftware/documentation/blob/main/projects/real-estate/filament/valuations.md) · [Filament standard](https://github.com/liberusoftware/documentation/blob/main/standards/FILAMENT.md) · [Module architecture](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md).

The application owns panel composition, routing, session integration and deployment. This module owns its focused presentation and delegates domain behavior to reviewed public owner contracts. Keep host App aliases, duplicate records and client-only authorization outside reusable package boundaries.

Documented consumer project families:

- [Real Estate](https://github.com/liberusoftware/project-real-estate-laravel-filament): Listings, sales, lettings and agency operations.

Other web, mobile and desktop presentations are listed in the [project catalog](https://github.com/liberusoftware/documentation/blob/main/projects/REPOSITORIES.md). Those skeletons provide starting structures; links do not establish feature parity, compatible releases or completed device/API integrations. Independent presentation packages keep their own Composer, npm or pub metadata.
<!-- module-presentation-guide:end -->
