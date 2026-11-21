# Real Estate Viewings Filament

The resource includes explicit confirm, complete, cancel, and no-show actions
delegated to the core module.

Filament 5 viewing resource. Composer is unprefixed; GitHub source uses `module-real-estate-viewings-filament`.

<!-- module-presentation-guide:begin -->
## Presentation architecture and project hosts

- Capability/source key: `liberusoftware/real-estate-viewings`.
- Actual Composer identity: `liberusoftware/real-estate-viewings-filament`.
- [Filament capability plan](https://github.com/liberusoftware/documentation/blob/main/projects/real-estate/filament/viewings.md) · [Filament standard](https://github.com/liberusoftware/documentation/blob/main/standards/FILAMENT.md) · [Module architecture](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md).

The application owns panel composition, routing, session integration and deployment. This module owns its focused presentation and delegates domain behavior to reviewed public owner contracts. Keep host App aliases, duplicate records and client-only authorization outside reusable package boundaries.

Documented consumer project families:

- [Real Estate](https://github.com/liberusoftware/project-real-estate-laravel-filament): Listings, sales, lettings and agency operations.

Other web, mobile and desktop presentations are listed in the [project catalog](https://github.com/liberusoftware/documentation/blob/main/projects/REPOSITORIES.md). Those skeletons provide starting structures; links do not establish feature parity, compatible releases or completed device/API integrations. Independent presentation packages keep their own Composer, npm or pub metadata.
<!-- module-presentation-guide:end -->
