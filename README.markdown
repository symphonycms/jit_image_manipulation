# JIT Image Manipulation #

"Just in time" image manipulation for Symphony. It is part of the Symphony core download package.

- Version: 1.14
- Date: 11th November 2011
- Requirements: Symphony 2.0.5 or later
- Author: Alistair Kearney, alistair@symphony-cms.com
- Contributors: [A list of contributors can be found in the commit history](http://github.com/symphonycms/jit_image_manipulation/commits/master)
- GitHub Repository: <http://github.com/symphonycms/jit_image_manipulation>

## Synopsis

A simple way to manipulate images on the fly via the URL. Supports caching, image quality settings and loading of offsite images.

## Installation

Information about [installing and updating extensions](http://symphony-cms.com/learn/tasks/view/install-an-extension/) can be found in the Symphony documentation at <http://symphony-cms.com/learn/>.

## Updating

Due to some `.htaccess` changes in Symphony 2.0.6+, it is recommended that you edit your core Symphony `.htaccess` to remove anything
before 'extensions/' in the JIT rewrite. It should look like the following regardless of where you installed Symphony:

	### IMAGE RULES
	RewriteRule ^image\/(.+\.(jpg|gif|jpeg|png|bmp))$ extensions/jit_image_manipulation/lib/image.php?param=$1 [L,NC]

It is not absolutely necessary to do this, but may prevent problems with future releases.

## Usage

### Basics

The image manipulation is controlled via the URL, eg.:

	<img src="{$root}/image/2/80/80/5{image/@path}/{image/filename}" />

The extension accepts four numeric settings for the manipulation:

1. mode
2. width
3. height
4. reference position (for cropping only)
5. background color (for cropping only)

There are four possible modes:

- `0` none
- `1` resize
- `2` resize and crop (used in the example)
- `3` crop
- `4` resize to fit

If you're using mode `2` or `3` for image cropping you need to specify the reference position:

	+---+---+---+
	| 1 | 2 | 3 |
	+---+---+---+
	| 4 | 5 | 6 |
	+---+---+---+
	| 7 | 8 | 9 |
	+---+---+---+

If you're using mode `2` or `3` for image cropping, there is an optional fifth background color setting. This can accept shorthand or full hex colors.

- *For `.jpg` images, it is advised to use this if the crop size is larger than the original, otherwise the extra canvas will be black.*
- *For transparent `.png` or `.gif` images, supplying the background color will fill the image. This is why the setting is optional*

The extra fifth setting makes the url look like this:

	<img src="{$root}/image/2/80/80/5/fff/{image/@path}/{image/filename}" />

- *If you wish to crop and maintain the aspect ratio of an image but only have one fixed dimension (that is, width or height), simply set the other dimension to 0*

### Trusted Sites

In order pull images from external sources, you must set up a white-list of trusted sites. To do this, goto "System > Preferences" and add rules to the "JIT Image Manipulation" rules textarea. To match anything use a single asterisk (*).