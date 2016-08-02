# JIT Image Manipulation

> A simple way to manipulate images "just in time" via the URL.
> Supports caching, image quality settings and loading of offsite images.

## TL;DR

- [Installation](#installation)
- [Options](#options)
- [Updating](#updating)
- [Usage](#usage)
    + [Basics](#basics)
    + [External sources & Trusted Sites](#external-sources--trusted-sites)
    + [Recipes](#recipes)
    + [Force Download](#force-download)

## Installation

Information about [installing and updating extensions](http://getsymphony.com/learn/tasks/view/install-an-extension/) can be found in the [Symphony documentation](http://getsymphony.com/learn/).

## Options

Most options are editable at Symphony's Preferences page.
A complete listing of the possible config settings:

```php
'image' => array(
    'cache' => '1',                     // 1/0 (hidden)
    'quality' => '90',                  // 10-100 (hidden)
    'disable_regular_rules' => 'no',    // yes/no (editable)
    'disable_upscaling' => 'yes',       // yes/no (editable)
    'disable_proxy_transform' => 'yes', // yes/no (editable)
    'allow_origin' => '',               // string (editable)
    'max_age' => 259200,                // integer (hidden)
    'memory_exhaustion_factor' => ''    // string/float/int (hidden)
    'cache_invalidation_odds' => ''     // string/float (hidden)
),
```

### cache

Setting this to '1' will activate the file cache, meaning request will be save to disk and served later

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

The value, in seconds, for the max-age specifier in the Cache-Control HTTP Header. Setting the value to 0 will disable the specifier. Default value is 3 days.

### memory_exhaustion_factor

The value (as a multiplicand) used to estimate the needed memory to execute the requested JIT transformation. This is useful when trying to prevent memory exhaustion and preserve resources for other requests.
Setting this value too high would overestimate the needed memory while setting it to low may not prevent memory exhaustion at all.
Recommended settings would be between 1.7 and 2.1.
Setting the value to 0, '' or null will disable the feature.
Default value is null (disabled).

### cache_invalidation_odds

Setting this value will make the cache invalidation checks more or less frequent.
If you care more about performance than serving a validated cache file, you can control the odds of doing a cache invalidation check.
We will generate a random number between 0 and 1 and compare it against your odds value.
By setting it to 0.1, the cache should only be validated 10% of the time.
In contrast, setting it to 0.9 would make the check happen really frequently.
Setting the value to 1, '' or null will disable the feature and always force the check.
Setting the value to 0 will prevent any cache validation.
Default value is null (disabled).

## Updating

### 2.1.0

Version `2.0.0` broke a couple of things and lacked some features present in versions `1.x`.
Hopefully, this version restores all features you enjoyed in `1.x` versions.
End users and site administrators should not see any differences, but developers should read the [complete release notes](https://github.com/symphonycms/jit_image_manipulation/releases/tag/2.1.0) since there were some minor API changes.

`2.0.0` also contained a regression bug that reverted the Apache rewrite rule to one matching extensions.
The updater should handle this. The Apache rewrite rule is now:

	### IMAGE RULES
	RewriteRule ^image\/(.+)$ index.php?mode=jit&param=$1 [B,L,NC]

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

In order pull images from external sources, you must set up a whitelist of trusted sites. To do this, go to "System > Preferences" and add rules to the "JIT Image Manipulation" rules textarea. To match anything, use a single asterisk (`*`).

The URL then requires a sixth parameter, external, (where the fourth and fifth parameter may be optional), which is simply `1` or `0`. By default, this parameter is `0`, which means the image is located on the same domain as JIT. Setting it to `1` will allow JIT to process external images provided they are on the Trusted Sites list.

	<img src="{$root}/image/1/80/80/1/{full/path/to/image}" />
	                                ^ External parameter

You can also include the protocol in the full path of the image. This can eliminate a redirection when requesting images from a domain that upgrade insecure requests.

	<img src="{$root}/image/1/80/80/1/https://{full/path/to/image}" />

### Recipes

Recipes are named rules in JIT settings which help improve security and convenience. They can be edited at the preferences page in the JIT section and are saved in `/workspace/jit-image-manipulation/recipes.php`. An image using a recipe called `thumbnail` might look like:

	<img src="{$root}/image/thumbnail{image/@path}/{image/filename}" />

You can completely disable dynamic JIT rules and choose to use recipes only, which will prevent a malicious user from hammering your server with large or frequent JIT requests. Be aware disabling dynamic rules also applies to any backend image previews you may have set up. Consider making a named recipe for backend image previews.

Recipes can be copied between installations and changes will be reflected by every image using this recipe.

### Force download

It is possible to force download of your resized image by creating a new webserver rewrite rule.
Simply add `&save` to the substitution argument (the target) of a new rewrite rule. For Apache:

	### Additional rewrite rule to create a new `image-download` endpoint:
	RewriteRule ^image-download\/(.+)$ index.php?mode=jit&param=$1&save [B,L,NC]

Note that merely adding `&save` to an `img` tag's `src` parameter will not result in a download as for reasons of security and sanity our rewrite rule does not have Apache use URL query string parameters from the original request.
