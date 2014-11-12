/*
*	We are hiding the deactivate button for this plugin because it's critical for Object Storage to work correctly. 
*	If object sotorage is deactivated, then you could very, very easily lose all of your media on your wordpress site 
* 	with no way to recover it. If you REALLY want to deactivate it, then delete this plugin from your composer.json and 
* 	run composer update (then you probably want to either remove the downloaded files or add them to a .cfignore.
*/

jQuery(function() {	
	jQuery('#ibm-object-storage').find('.deactivate').css('display', 'none');
});
