$(document).ready(function() {

	/*
        var options = {
                selectStartLabel: 'Select Images from Another Page'
        };
	var page_id = $("#page_id").val();

	$("#page_id").ProcessPageList(options).hide()
		.bind('pageSelected', function(event, data) {
			if(data.id != page_id) {
				window.location = "./?id=" + data.id + '&modal=1'; 
			}
			
			///window.location = "./id=" + $("#
			//selectedPageData = data;
			//selectedPageData.url = config.urls.root + data.url.substring(1);
			//$("#link_page_url").val(selectedPageData.url);
		});
	*/




	function setupImage($img) {

		var originalWidth = $img.width();
		var $selected_image_dimensions = $("#selected_image_dimensions"); 
		
		function populateResizeDimensions() {
			$selected_image_dimensions.html($img.width() + "x" + $img.height()); 
		}

		$img.resizable({
			aspectRatio: true,
			stop: function() {
				$img.attr('width', $img.width()).attr('height', $img.height()); 
				if(originalWidth != $img.width()) $img.addClass('resized'); 
			},
			resize: populateResizeDimensions
		});

		$("#selected_image_class").change(function() {
			var resized = $img.is(".resized"); 
			$img.attr('class', $(this).val()); 	
			if(resized) $img.addClass('resized'); 
		});

		$("#selected_image_description").focus(function() {
			$(this).siblings('label').hide();
		}).blur(function() {
			if($(this).val().length < 1) $(this).siblings('label').show();
		}).change(function() {
			if($(this).val().length < 1) $(this).siblings('label').show();
				else $(this).siblings('label').hide();
		}).change();

		populateResizeDimensions();
	}; 

	var $img = $("#selected_image"); 

	if($img.size() > 0) {
		$img = $img.first();

		if($img.width() > 0) setupImage($img); 
			else $img.load(setupImage); 

	}

}); 
