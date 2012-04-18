jQuery(document).ready(function($) {
	$('.jit-duplicator')
		.symphonyDuplicator({
			orderable: true,
			collapsible: true
		})
		.on('input blur keyup', '.instance input[name*="[name]"]', function(event) {
			var label = $(this),
				value = label.val();

			// Empty url-parameter
			if(value == '') {
				value = Symphony.Language.get('Untitled');
			}

			// Update title
			label.parents('.instance').find('header strong').text(value);
		});
});
