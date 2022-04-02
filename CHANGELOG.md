# Slug Equals Title Changelog
All notable changes to this project will be documented in this file.

## 2.0.0-beta.1 - 2022-04-02
### Added
- Added support for [Craft CMS 4.0.0-beta.3](https://github.com/craftcms/cms/releases/tag/4.0.0-beta.3).
- Added support for [Unified Element Editor](https://craftcms.com/docs/4.x/extend/updating-plugins.html#unified-element-editor).
- Added support for [Craft Commerce 4.0.0-beta.1](https://github.com/craftcms/commerce/releases/tag/4.0.0-beta.1).

## 1.3.4 - 2021-11-28
### Fixed
- Use `StringHelper::slugify` before setting `$entry->slug`. Fix #15. 

## 1.3.3 - 2021-10-17
### Updated
- Updated Icon.

## 1.3.2 - 2021-09-06
### Fixed
- Fix categories. #14

## 1.3.1 - 2021-02-02
### Fixed
- Only register Asset Bundle when necessary. #12

## 1.3.0 - 2021-01-04
### Added
- Added support for Commerce Products and Categories.

## 1.2.1 - 2020-08-18
### Fixed
- Fixed a bug that caused the lightswitch to disappear on Craft <= 3.4. Thanks @skoften! #10

## 1.2.0 - 2020-07-31
### Added
- Support for `limitAutoSlugsToAscii`. #8

## 1.1.5 - 2020-07-29
### Fixed
- Update element id JavaScript selector (`entryId` -> `sourceId`)

## 1.1.4 - 2019-09-05
### Fixed
- Fixed bug that occurred while resaving entries via console. #3

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
