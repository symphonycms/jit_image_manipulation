jQuery(document).ready(function($) {
	var duplicator = $('.jit-duplicator');
	duplicator.symphonyDuplicator({
		orderable: true,
		collapsible: true
	});
	duplicator.bind('collapsestop', function(event, item) {
		var instance = $(item);
		instance.find('.header > span:not(:has(i))').append(
			// $('<i>' + instance.find('input[name$="\\[from\\]"]').attr('value') + '&nbsp;&rarr;&nbsp;' + instance.find('input[name$="\\[to\\]"]').attr('value') + '</i>')
			$('<i>' + instance.find('input[type=text]').first().attr('value') + '</i>')
		);
	});
	duplicator.bind('expandstop', function(event, item) {
		$(item).find('.header > span > i').remove();
	});
	duplicator.trigger('restorestate');
});
