
/**
 * ProcessWire Image Plugin for TinyMCE
 *
 * Works with ProcessPageEditImage module to support insertion and manipulation of images in a TinyMCE field
 * 
 * ProcessWire 2.x 
 * Copyright (C) 2010 by Ryan Cramer 
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 * 
 * http://www.processwire.com
 * http://www.ryancramer.com
 *
 */


var tinymceSelection = null; 
var $iframe; 

(function() {
	tinymce.create('tinymce.plugins.PwImagePlugin', {
		init : function(ed, url) {
			this.editor = ed;

			// Register commands
			ed.addCommand('mcePwImage', function() {

				// Internal image object like a flash placeholder
				if (ed.dom.getAttrib(ed.selection.getNode(), 'class').indexOf('mceItem') != -1)
					return;

				var page_id = $("#Inputfield_id").val(); 
				var file = '';
				var imgClass = '';
				var imgWidth = 0;
				var imgHeight = 0;
				var imgDescription = '';
				var imgLink = '';
				var se = ed.selection;
				var $node = null;
				var nodeParent = ed.dom.getParent(se.getNode(), 'A');
                                var $nodeParent = $(nodeParent); 

				if(!se.isCollapsed()) {

					$node = $(se.getNode());
					src = $node.attr('src'); 
					parts = src.split('/'); 
					file = parts.pop();
					imgClass = $node.attr('class'); 
					imgWidth = $node.attr('width');
					imgHeight = $node.attr('height'); 
					imgDescription = $node.attr('alt'); 
					imgLink = $nodeParent.is("a") ? $nodeParent.attr('href') : '';

					parts = parts.reverse();
					page_id = 0; 

					for(n = 0; n < parts.length; n++) {
						page_id = parseInt(parts[n]); 
						if(page_id > 0) break;
					}
				}

				var modalUri = config.urls.admin + 'page/image/';
				var queryString = '?id=' + page_id + '&modal=1';

				var windowHeight = $(window).height() - 250; 
				var windowWidth = $(window).width() - 200; 

				if(file.length) queryString += "&file=" + file; 
				if(imgWidth) queryString += "&width=" + imgWidth; 
				if(imgHeight) queryString += "&height=" + imgHeight; 
				if(imgClass.length) queryString += "&class=" + imgClass; 
				if(imgDescription.length) queryString += "&description=" + escape(imgDescription);
				if(imgLink.length) queryString += "&link=" + escape(imgLink);
				queryString += "&winwidth=" + windowWidth; 

				$iframe = $('<iframe id="pwimage_iframe" width="100%" frameborder="0" src="' + modalUri + queryString + '"></iframe>'); 

				$iframe.dialog({
					title: "Select Image", 
					height: windowHeight,
					width: windowWidth,
					position: [100, 80], 
					modal: true,
					overlay: {
						opacity: 0.7,
						background: "black"
					}
				}).width(windowWidth).height(windowHeight);

				$iframe.load(function() {

					var $i = $iframe.contents();

					if($i.find("#selected_image").size() > 0) {

						$iframe.dialog("option", "buttons", {

							"Insert This Image": function() {

								var $img = $("#selected_image", $i); 
								var url = $img.attr('src'); 
								var width = $img.attr('width');
								var height = $img.attr('height'); 
								var alt = $("#selected_image_description", $i).val();
								var cls = $img.removeClass('ui-resizable').attr('class'); 
								var link = $("#selected_image_link:checked", $i).val();
                                

								if(alt && alt.length > 0) alt = $("<div />").text(alt).html().replace(/"/g, '&quot;'); 

								function insertImage(url) {
									var html = '<img class="' + cls + '" src="' + url + '" mce_src="' + url + '" '; 
									if(width > 0) html += 'width="' + width + '" '; 
									if(height > 0) html += 'height="' + height + '" '; 
									html += 'alt="' + alt + '" />';
									if(link && link.length > 0) html = "<a href='" + link + "'>" + html + "</a>";
									if(nodeParent && $nodeParent.is("a")) se.select(nodeParent); // add it to the selection 
									//se.select(nodeParent); // add it to the selection 
									tinyMCE.execCommand('mceInsertContent', false, html);
									$iframe.dialog("close"); 
								}

								if($img.is('.resized')) {

									$iframe.dialog("disable").dialog("option", "title", "Saving Resized Image"); 
									$img.removeClass("resized"); 
									cls = $img.attr('class'); 
									file = $img.attr('src'); 
									file = file.substring(file.lastIndexOf('/')+1); 

									$.get(modalUri + 'resize?id=' + page_id + '&file=' + file + '&width=' + width + '&height=' + height, function(data) {
										var $div = $("<div></div>").html(data); 
										$img = $div.find("#selected_image"); 
										url = $img.attr('src'); 	
										height = $img.attr('height'); 
										width = $img.attr('width'); 
										insertImage(url); 
									}); 
								} else {
									// cls = $img.attr('class'); 
									insertImage($img.attr('src')); 
								}

							},

							"Select Another Image": function() {
								$iframe.attr('src', modalUri + '?id=' + page_id + '&modal=1'); 
								$iframe.dialog("option", "buttons", {}); 
							},

							Cancel: function() {
								$iframe.dialog("close"); 
							}

						}).dialog("option", "title", "Edit Image").width(windowWidth).height(windowHeight);


					} else {
						$iframe.dialog("option", "buttons", {
							Cancel: function() {
								$iframe.dialog("close"); 
							}
						}).width(windowWidth).height(windowHeight);
					}



				});


			

			});

			// Register buttons
			ed.addButton('image', {
				title : 'pwimage.image_desc',
				cmd : 'mcePwImage'
			});

		},

		getInfo : function() {
			return {
				longname : 'ProcessWire TinyMCE Image Select Plugin',
				author : 'Ryan Cramer',
				authorurl : 'http://www.ryancramer.com',
				infourl : 'http://www.processwire.com/',
				version : tinymce.majorVersion + "." + tinymce.minorVersion
			};
		}
	});

	// Register plugin
	tinymce.PluginManager.add('pwimage', tinymce.plugins.PwImagePlugin);
})();

