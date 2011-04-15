# JIT Image Manipulation #

"Just in time" image manipulation for Symphony.
It is part of the Symphony core download package.

- Version: 1.10
- Date: 15th April 2011
- Requirements: Symphony 2.0.5 or later
- Author: Alistair Kearney, alistair@symphony-cms.com
- Constributors: [A list of contributors can be found in the commit history](http://github.com/symphonycms/jit_image_manipulation/commits/master)
- GitHub Repository: <http://github.com/symphonycms/jit_image_manipulation>

## Synopsis

A simple way to manipulate images on the fly via the URL. Supports caching, image quality settings and loading of offsite images.

## Installation

Information about [installing and updating extensions](http://symphony-cms.com/learn/tasks/view/install-an-extension/) can be found in the Symphony documentation at <http://symphony-cms.com/learn/>.

## Updating

Due to some `.htaccess` changes in Symphony 2.0.6+, it is recommended that you edit your core Symphony .htaccess to remove anything
before 'extensions/' in the JIT rewrite. It should look like the following regardless of where you installed Symphony:

	### IMAGE RULES	
	RewriteRule ^image\/(.+\.(jpg|gif|jpeg|png|bmp))$ extensions/jit_image_manipulation/lib/image.php?param=$1 [L,NC]

It is not absolutely necessary to do this, but may prevent problems with future releases.

## Usage

### Basics

The image manipulation is controlled via the URL, e. g.:

	<img src="{$root}/image/2/80/80/5{image/@path}/{image/filename}" />

The extension accepts four numeric settings for the manipulation:

1. mode
2. width
3. height
4. reference position (for cropping only)

There are four possible modes:

- `0` none
- `1` resize
- `2` resize and crop (used in the example)
- `3` crop

If you're using mode `2` or `3` for image cropping you need to specify the reference position:

	+---+---+---+
	| 1 | 2 | 3 |
	+---+---+---+
	| 4 | 5 | 6 |
	+---+---+---+
	| 7 | 8 | 9 |
	+---+---+---+

### Trusted Sites

In order pull images from external sources, you must set up a white-list of trusted sites. To do this, goto "System > Preferences" and add rules to the "JIT Image Manipulation" rules textarea. To match anything use a single asterisk (*).

## Change Log

**Version 1.10**

- Compatibility with Symphony 2.2

**Version 1.09**

- Sending `ETag` header with response
- Added support for `HTTP_IF_MODIFIED_SINCE` and `HTTP_IF_NONE_MATCH` request headers, which will mean a `304 Not Modified` header can be set (Thanks to Nick Dunn for helping on this one)
- Added Portuguese and Italian translations (Thanks to Rainer Borene & Simone Economo for those)

**Version 1.08**

- Added French localisation
- Adjusted German localisation
- Fixed a Symphony 2.0.7RC2 compatibility issue.

**Version 1.07**

- Added localisation files for Dutch, German, Portuguese (Brazil) and Russian
- `trusted()` will look for the `jit-trusted-sites` before attempting to return its contents. This prevents warnings from showing up in the logs.

**Version 1.06**

- Code responsible for `.htaccess` update will no longer try to append the rewrite base to for path to extensions folder 

**Version 1.05**

- Fixed bug introduced by usage of the imageAntiAlias() function
- Errors and warnings are logged in the main Symphony log
- A dump of internal params are logged in addition to any errors

**Version 1.04**

- Adding support for alpha masked images.

**Version 1.03**

- Minor changes to how `DOCROOT`` is determined

**Version 1.02**

- Disabling extension will remove rule from `.htaccess`

**Version 1.01**

- Updated to work with 2.0.2 config changes
