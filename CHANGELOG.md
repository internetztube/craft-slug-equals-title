# Slug Equals Title Changelog
All notable changes to this project will be documented in this file.

## 1.3.6 - 2023-07-20
### Fixed
- Fixed bug that occurred when title was empty. [#19](https://github.com/internetztube/craft-slug-equals-title/issues/19) [#20](https://github.com/internetztube/craft-slug-equals-title/pull/20) Thank you [@jorisnoo](https://github.com/jorisnoo)! 

## 1.3.5 - 2023-02-09
### Fixed
- Use `ElementHelper::generateSlug` instead of `StringHelper::slugify`. Thank you, [@engram-design](https://github.com/engram-design). [#18](https://github.com/internetztube/craft-slug-equals-title/pull/18)

## 1.3.4 - 2021-11-28
### Fixed
- Use `StringHelper::slugify` before setting `$entry->slug`. Fix [#15](https://github.com/internetztube/craft-slug-equals-title/issues/15). 

## 1.3.3 - 2021-10-17
### Updated
- Updated Icon.

## 1.3.2 - 2021-09-06
### Fixed
- Fix categories. [#14](https://github.com/internetztube/craft-slug-equals-title/issues/14)

## 1.3.1 - 2021-02-02
### Fixed
- Only register Asset Bundle when necessary. [#12](https://github.com/internetztube/craft-slug-equals-title/issues/12)

## 1.3.0 - 2021-01-04
### Added
- Added support for Commerce Products and Categories.

## 1.2.1 - 2020-08-18
### Fixed
- Fixed a bug that caused the lightswitch to disappear on Craft <= 3.4. Thanks @skoften! [#10](https://github.com/internetztube/craft-slug-equals-title/issues/10)

## 1.2.0 - 2020-07-31
### Added
- Support for `limitAutoSlugsToAscii`. [#8](https://github.com/internetztube/craft-slug-equals-title/issues/8)

## 1.1.5 - 2020-07-29
### Fixed
- Update element id JavaScript selector (`entryId` -> `sourceId`)

## 1.1.4 - 2019-09-05
### Fixed
- Fixed bug that occurred while resaving entries via console. [#3](https://github.com/internetztube/craft-slug-equals-title/issues/3)

## 1.1.3 - 2019-08-18
### Fixed
- Fix CP slug field overwritten status bug.

## 1.1.2 - 2019-08-15
### Fixed
- Logic of get parameter was adjusted.

### Changed
- Icon changed.

## 1.1.1 - 2019-08-15
### Added
- Support for disabling overwrite for a single entry
- Support for section types

### Removed
- Support for non-entry elements

## 1.0.1 - 2019-07-14
### Added
- Support for selectable ElementsTypes in CP.

## 1.0.0 - 2019-07-14
### Added
- Initial release
