# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [11.2.0] - 2026-11-21

### Added
- Feature coverage for real-estate workflows, API routes, Filament resources, organization migrations, and portable module migrations.
- Standalone test setups for additional foundation modules.

### Changed
- Harden tenant checks and workflow actions across listings, offers, and real-estate Livewire components.
- Preserve structured property and match-profile data through API and Filament forms.

### Fixed
- Correct viewing time validation and timezone handling, property valuation date calculations, and JSON persistence for parking and garden attributes.

## [11.1.1] - 2026-11-05

### Added
- Super admin installation setup for SaaS and dedicated deployments.
- Workspace onboarding for branches, portal credentials, syncing settings and analytics.
- Personal onboarding for profile and panel language preferences.

### Changed
- Clearer dashboard shortcuts, navigation and setup review screens.

### Fixed
- Align the tenancy dependency with the published 1.0.1 module.
- Update affected Symfony components to patched releases.
- Require complete portal credential pairs when showing saved connection status.
- Preserve tenant isolation when switching workspaces during setup.

### Added
- Initial documentation structure with CHANGELOG, CONTRIBUTING, and SECURITY policies

### Changed
- Enhanced project documentation and specifications
