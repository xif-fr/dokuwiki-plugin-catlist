/**
 * Js for plugin catlist
 * Adding pages
 *
 * @license   MIT
 * @author    Félix Faisant <xcodexif@xif.fr>
 *
 */

function catlist_button_add_page (element, ns) {
	var addPageForm = element.parentNode;
	addPageForm.innerHTML = "";
	var addPageLabel = document.createElement('label');
	addPageLabel.innerHTML = ns;
	var addPageInput = document.createElement('input');
	addPageInput.type = 'text';
	addPageInput.id = 'catlist_addpage_id';
	addPageInput.onkeyup = function (evt) {
		var key = evt.keyCode || evt.which;
		if (key == 13) 
			jQuery('#catlist_addpage_btn').click();
	};
	addPageLabel.htmlFor = 'catlist_addpage_id';
	var addPageValidButton = document.createElement('button');
	addPageValidButton.className = 'button';
	addPageValidButton.innerHTML = "Ok";
	addPageValidButton.id = 'catlist_addpage_btn';
	jQuery(addPageForm).append(addPageLabel).append(addPageInput).append(addPageValidButton);
	addPageInput.focus();
	jQuery(addPageValidButton).click(function(){
		if (addPageInput.value.length == 0) {
			addPageInput.focus();
			return;
		}
		var pagename = addPageInput.value;
		pagename = encodeURI(pagename);
		if (typeof String.prototype.normalize === "function")
		pagename = pagename.normalize('NFD')
		                   .replace(/[\u0300-\u036f]/g, ""); // eliminates diacritics            
		pagename = pagename.replace(/[^a-zA-Z0-9._:-%]+/g, catlist_sepchar) // transforms characters not allowed as pagename in `catlist_sepchar`
		                   .replace(/%(?![A-Fa-f0-9]{2})/, catlist_sepchar) // replace "%" if it is not the part of an URL encoded character
		                   .replace(/^[._-]+/, "") // eliminates '.', '_' and '-' at the beginning and end
		                   .replace(/[._-]+$/, "")
		                   .replace(new RegExp(catlist_sepchar+'{2,}','g'), catlist_sepchar) // squash multiple sepchars into one
		                   .toLowerCase();
		var newPageID = ns + pagename;
		if (catlist_useslash && catlist_userewrite != 0) {
			newPageID = newPageID.replace(/:/g, '/');
		}
		switch (catlist_userewrite) {
			case 0: 
				newPageURL = catlist_baseurl + catlist_basescript + '?id=' + newPageID + '&do=edit'; break;
			case 1:
				newPageURL = catlist_baseurl + newPageID + '?do=edit'; break;
			case 2:
				newPageURL = catlist_baseurl + catlist_basescript + '/' + newPageID + '?do=edit'; break;
		}
		window.location.href = newPageURL;
	});
}
