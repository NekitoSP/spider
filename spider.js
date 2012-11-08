$(document).ready(function(){
	var collapsedSize = '90px'; // how many pixs to show
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
});