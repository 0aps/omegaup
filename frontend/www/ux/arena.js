$(document).ready(function() {
  
	function makeWorldClockLink(date) {
		try {
			return "http://timeanddate.com/worldclock/fixedtime.html?iso=" + date.toISOString();
		} catch (e) {
			return '#';
		}
	}
	
	var omegaup = new OmegaUp();

	omegaup.getContests(function(data) {
		var list = data.contests;
		var current = $('#contest-list');
		var past = $('#past-contests');
		var now = new Date();
		
		for (var i = 0, len = list.length; i < len; i++) {
			var start = new Date(list[i].start_time);
			var end = new Date(list[i].finish_time);
			((end > now) ? current : past).append(
				$('<tr>' +
					'<td><a href="/arena/' + list[i].alias + '">' + list[i].title + '</a></td>' +
					'<td>' + list[i].description + '</td>' +
					'<td><a href="' + makeWorldClockLink(start) + '">' + start.format() + '</a></td>' +
					'<td>' + end.format() + '</td>' + (end < now ? '<td><a href="/arena/' + list[i].alias + '/practice/">Práctica</a></td>' : '') + 
				'</tr>')
			);
		}

		$('#loading').fadeOut('slow');
		$('#root').fadeIn('slow');
	});

	$('#contest-list tr').live('click', function() {

	});
});
