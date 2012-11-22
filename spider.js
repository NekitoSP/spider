
$(document).ready(function(){

	// Hide/Show for job descriptions
	var collapsedSize = '130px'; // how many pixs to show
	$(".spider-item-description").each(function() {
	    var h = this.scrollHeight;
	    //console.log(h);
	    var div = $(this);
	    if (h > 30) {
	        div.css('height', collapsedSize);
	        div.after('<a id="more" class="item" href="#">Read more</a><br/>');
	        var link = div.next();
	        link.click(function(e) {
	            e.stopPropagation(); // to determine if this method was ever called (on that event object)
	
	            if (link.text() != 'Collapse') {
	                link.text('Collapse');
	                div.animate({
	                    'height': h
	                });
	
	            } else {
	                div.animate({
	                    'height': collapsedSize
	                });
	                link.text('Read more');
	            }
	            return false;
	        });
	    }
	});
	
	
	// Parsing search text input and then redirecting
	$('#command').keydown(function(e) {
		if (e.which === 13) {
			var str = $('#command').val();
			
			// Removing spaces and replacing them with dashes
			var result = "";
			for (var i = 0; i < str.length; i++) {
				if (str.charAt(i) == " ") result += "-";
				else result += str.charAt(i);
			}
			
			// Building new URL
			var url = document.URL;
			var location = url.split('?');
			var url = location[0] + '/search/' + result;
			window.location = url;
		    
		    return false;
		}
   });
});