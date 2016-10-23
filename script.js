/**
 * Js for plugin catlist
 * Adding pages
 *
 * @license	  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author    Félix Faisant <xcodexif@xif.fr>
 *
 */

function button_add_page (element, baseUrl, script, ns, useslash, userewrite, sepchar) {
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
		var newPageID = ns + addPageInput.value;
		newPageID = str_replace(' ', sepchar, newPageID);
		newPageID = newPageID.toLowerCase();
		if (useslash && userewrite) {
			newPageID = str_replace(':', '/', newPageID);
		}
		if (userewrite) {
			newPageURL = baseUrl + newPageID + '?do=edit';
		} else {
			newPageURL = baseUrl + script + '?id=' + newPageID + '&do=edit';
		}
		window.location.href = newPageURL;
	});
}

/**************************************** UTILS ****************************************/

function str_replace (search, replace, subject, count) {
	// from http://phpjs.org/functions/str_replace
	var i = 0, j = 0, temp = '', repl = '', sl = 0, fl = 0,
	f = [].concat(search),
	r = [].concat(replace),
	s = subject,
	ra = Object.prototype.toString.call(r) === '[object Array]',
	sa = Object.prototype.toString.call(s) === '[object Array]';
	s = [].concat(s);
	if (count) this.window[count] = 0;
	for (i = 0, sl = s.length; i < sl; i++) {
		if (s[i] === '') continue;
		for (j = 0, fl = f.length; j < fl; j++) {
			temp = s[i] + '';
			repl = ra ? (r[j] !== undefined ? r[j] : '') : r[0];
			s[i] = (temp).split(f[j]).join(repl);
			if (count && s[i] !== temp) {
				this.window[count] += (temp.length - s[i].length) / f[j].length;
			}
		}
	}
	return sa ? s : s[0];
}
