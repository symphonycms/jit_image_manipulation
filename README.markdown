# JIT Image Manipulation

> A simple way to manipulate images "just in time" via the URL. Supports caching, image quality settings and loading of offsite images.

## TL;DR

- [Installation)(#installation)
- [Updating](#updating)
- [Usage](#usage)
- [External sources & Trusted Sites](#external-sources--trusted-sites)
- [Recipes](#recipes)

## Installation

Information about [installing and updating extensions](http://getsymphony.com/learn/tasks/view/install-an-extension/) can be found in the [Symphony documentation](http://getsymphony.com/learn/).

## Options

This extensions offers a couple of useful configuration options.
Most of them are editable on Symphony's Preferences page.

```php
'image' => array(
    'cache' => '1',                     // 1/0 (hidden)
    'quality' => '90',                  // 10-100 (hidden)
    'disable_regular_rules' => 'no',    // yes/no (editable)
    'disable_upscaling' => 'yes',       // yes/no (editable)
    'disable_proxy_transform' => 'yes', // yes/no (editable)
    'allow_origin' => '',               // string (editable)
    'max_age' => null,                  // integer (hidden)
),
```

### cache

Setting this to '1' will activate the file cache, meaning request will be save and served later

### quality

The quality factor for the jpeg and png encoders

### disable_regular_rules

Setting this to 'yes' will disable numeric modes and only allow [custom recipes](#recipes)

### disable_upscaling

Setting this to 'yes' will disable image upscaling, i.e. the source size will be used as a max.

### disable_proxy_transform

Setting this to 'yes' will add a HTTP header to the response telling proxies to do not perform any optimization/transformation on the response

### allow_origin

If not empty, this value will be sent as the Cross-Origin HTTP header

### max_age

The value, in seconds, for the max-age specifier in the Cache-Control HTTP Header

## Updating

### 2.0.0

Since version `2.0.0`, the `.htaccess` rule now uses Symphony's custom launcher feature. The rule should look like

	### IMAGE RULES
	RewriteRule ^image\/(.+\.(jpg|gif|jpeg|png|bmp))$ index.php?mode=jit&param=$1 [B,L,NC]

### 1.17

This release raises the minimum requirement to Apache 2.2+.

### 1.15

Since version `1.15`, JIT configuration has moved from `/manifest/` to the `/workspace/` folder. This change will automatically happen when you update the extension from the "System > Extensions" page.

Due to some `.htaccess` changes in Symphony 2.0.6+, it is recommended that you edit your core Symphony `.htaccess` to remove anything before 'extensions/' in the JIT rewrite. It should look like the following regardless of where you installed Symphony:

	### IMAGE RULES
	RewriteRule ^image\/(.+\.(jpg|gif|jpeg|png|bmp))$ extensions/jit_image_manipulation/lib/image.php?param=$1 [L,NC]

It is not absolutely necessary to do this, but may prevent problems with future releases.

## Usage

### Basics

The image manipulation is controlled via the URL, eg.:

	<img src="{$root}/image/2/80/80/5/fff{image/@path}/{image/filename}" />

The extension accepts four numeric settings and one text setting for the manipulation.

1. mode
2. width
3. height
4. reference position (for cropping only)
5. background color (for cropping only)

There are five possible modes:

- `1` resize
- `2` resize and crop (used in the example)
- `3` crop
- `4` resize to fit
- `5` scale

If you're using mode `5`, the only parameter needed is a integer percentage value, i.e. `1 == 0.01 == 1%`.

If you're using mode `2` or `3` for image cropping you need to specify the reference position:

	+---+---+---+
	| 1 | 2 | 3 |
	+---+---+---+
	| 4 | 5 | 6 |
	+---+---+---+
	| 7 | 8 | 9 |
	+---+---+---+

If you're using mode `2` or `3` for image cropping, there is an optional fifth parameter for background color. This can accept shorthand or full hex colors.

- *For `.jpg` images, it is advised to use this if the crop size is larger than the original, otherwise the extra canvas will be black.*
- *For transparent `.png` or `.gif` images, supplying the background color will fill the image. This is why the setting is optional*

The extra fifth parameter makes the URL look like this:

	<img src="{$root}/image/2/80/80/5/fff{image/@path}/{image/filename}" />

- *If you wish to crop and maintain the aspect ratio of an image but only have one fixed dimension (that is, width or height), simply set the other dimension to 0*

### External sources & Trusted Sites

In order pull images from external sources, you must set up a white-list of trusted sites. To do this, go to "System > Preferences" and add rules to the "JIT Image Manipulation" rules textarea. To match anything use a single asterisk (`*`).

The URL then requires a sixth parameter, external, (where the fourth and fifth parameter may be optional), which is simply `1` or `0`. By default, this parameter is `0`, which means the image is located on the same domain as JIT. Setting it to `1` will allow JIT to process external images provided they are on the Trusted Sites list.

	<img src="{$root}/image/1/80/80/1/{full/path/to/image}" />
	                                ^ External parameter

### Recipes

Recipes are named rules for the JIT settings which help improve security and are more convenient. They can be edited on the preferences page in the JIT section and are saved in  `/workspace/jit-image-manipulation/recipes.php`. A recipe URL might look like:

	<img src="{$root}/image/thumbnail{image/@path}/{image/filename}" />

When JIT parses a URL like this, it will check the recipes file for a recipe with a handle of `thumbnail` and apply it's rules. You can completely disable dynamic JIT rules and choose to use recipes only which will prevent a malicious user from hammering your server with large or multiple JIT requests.

Recipes can be copied between installations and changes will be reflected by every image using this recipe.
