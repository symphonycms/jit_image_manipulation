jQuery(document).ready(function($) {
	var duplicator = $('.jit-duplicator');

	duplicator
		.symphonyDuplicator({
			orderable: true,
			collapsible: true
		})
		.on('keyup', '.instance input[name*="[name]"]', function(event) {
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
