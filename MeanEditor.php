<?php
# Alert the user that this is not a valid entry point to MediaWiki if they try to access the skin file directly.
if (!defined('MEDIAWIKI'))
{
	echo <<<EOT
	To install the MeanEditor extension put the following line in LocalSettings.php:
	require_once( "$IP/extensions/MeanEditor/MeanEditor.php" );
	
	See README for more information.
EOT;
	         exit( 1 );
}

$wgExtensionMessagesFiles['MeanEditor'] = dirname(__FILE__) . '/MeanEditor.i18n.php';
$wgExtensionCredits['other'][] = array(
	'name' => 'MeanEditor',
	'author' => 'Jacopo Corbetta and Alessandro Pignotti for Antonio Gulli',
	'description' => 'The mean, the safe and the ugly visual editor for non-technical users. Based on WYMeditor and jQuery.',
	'descriptionmsg' => 'meaneditor_desc',
	'url' => 'http://www.mediawiki.org/wiki/Extension:MeanEditor',
	'version' => '0.5.5'
);

function substitute_hashed_img_urls($text)
{
	while (preg_match('/\[\[Image:(.*?)\]\]/', $text, $matches)) {
		$img = $matches[1];
		$hash = md5($img);
		$folder = substr($hash, 0, 1) . 
			'/' . substr($hash, 0, 2);
		$tag = '<img alt="' . $img . '" src="' . $wgUploadPath .
			'/' . $folder . '/' . $img . '" />';
		$text = str_replace($matches[0], $tag, $text);
	}
	return $text;
}

function deny_visual_because_of($reason, &$edit_context)
{
	global $wgOut;
	wfLoadExtensionMessages('MeanEditor');
	$wgOut->addHTML('<p class="visual_editing_denied errorbox">' . wfMsg('no_visual') . '<em class="visual_editing_denied_reason">'.$reason.'</em></p>');
	# FIXME: Doesn't work. Why?
	#$edit_context->editFormTextBeforeContent .= '<p class="visual_editing_denied errorbox">The visual editor can\'t be used for this page. Most likely, it contains advanced or unsopported features. If you can, try editing smaller paragraphs.<br /><br />Reason: <em class="visual_editing_denied_reason">'.$reason.'</em></p>';
	# Maybe add a page to gather feedback
	return true;  # Show the standard textbox interface
}

# Return true to force traditional editing
function meaneditor_wiki2html($article, $user, &$edit_context, &$wiki_text)
{
	global $wgUploadPath, $wgArticlePath;
	wfLoadExtensionMessages('MeanEditor');
	$meaneditor_page_src = str_replace('$1', '', $wgArticlePath);
	
	# Detect code sections (lines beginning with whitespace)
	if (preg_match('/^[ \t]/m',$wiki_text))
		return deny_visual_because_of(wfMsg('reason_whitespace'), $edit_context);
		
	# Detect custom tags: only <br />, super/sub-scripts and references are supported at the moment
	# TODO: expand the safe list
	# Especially problematic tags (do not even think about supporting them):
	#      <p>  (would require special handling to disable normal paragraphing, confusing)
	#      <h*> (for headings not in TOC, strange closing tag)
	#      <b>,<i> (not to be confused with ''emphasis'' as <em>)
	#      <pre>, <nowiki> (if something gets implemented, better be the common leading spaces)
	$wiki_text=str_replace('<br />','__TEMP__TEMP__br',$wiki_text);
	$wiki_text=str_replace('<br>','__TEMP__TEMP__br',$wiki_text);
	$wiki_text=str_replace('<references />','__TEMP__TEMP__allreferences',$wiki_text);
	$wiki_text=str_replace('<ref>','__TEMP__TEMP__ref',$wiki_text);
	$wiki_text=str_replace('</ref>','__TEMP__TEMP__cref',$wiki_text);
	$wiki_text=str_replace('<sup>','__TEMP__TEMP__sup',$wiki_text);
	$wiki_text=str_replace('</sup>','__TEMP__TEMP__csup',$wiki_text);
	$wiki_text=str_replace('<sub>','__TEMP__TEMP__sub',$wiki_text);
	$wiki_text=str_replace('</sub>','__TEMP__TEMP__csub',$wiki_text);
	if (!((strpos($wiki_text, '<')===FALSE) && (strpos($wiki_text, '>')===FALSE)))
		return deny_visual_because_of(wfMsg('reason_tag'), $edit_context);
	$wiki_text=str_replace('__TEMP__TEMP__br','<br />', $wiki_text);
	$wiki_text=str_replace('__TEMP__TEMP__allreferences','references_here',$wiki_text);
	$wiki_text=str_replace('__TEMP__TEMP__sup','<sup>',$wiki_text);
	$wiki_text=str_replace('__TEMP__TEMP__csup','</sup>',$wiki_text);
	$wiki_text=str_replace('__TEMP__TEMP__sub','<sub>',$wiki_text);
	$wiki_text=str_replace('__TEMP__TEMP__csub','</sub>',$wiki_text);
	$wiki_text=str_replace('__TEMP__TEMP__ref','<ref>',$wiki_text);
	$wiki_text=str_replace('__TEMP__TEMP__cref','</ref>',$wiki_text);
	
	# This characters are problematic only at line beginning
	$unwanted_chars_at_beginning = array(':', ';');
	foreach ($unwanted_chars_at_beginning as $uc)
		if (preg_match('/^'.$uc.'/m',$wiki_text))
			return deny_visual_because_of(wfMsg('reason_indent', $uc), $edit_context);
	
	# <hr>, from Parser.php... TODO: other regexps can be directly stolen from there
	$wiki_text=preg_replace('/(^|\n)-----*/', '\\1<hr />', $wiki_text);
	
	#Collapse multiple newlines
	# TODO: Compare Wikipedia:Don't_use_line_breaks
	$wiki_text=preg_replace("/\n\n+/","\n\n",$wiki_text);

	$wiki_text=preg_replace('/^(.+?)$/m','<p>$1</p>',$wiki_text);

	#$wiki_text=preg_replace('/\'\'\'(.*?)\'\'\'/','<strong>$1</strong>',$wiki_text);
	#$wiki_text=preg_replace('/\'\'(.*?)\'\'/','<em>$1</em>',$wiki_text);
	$obp = new Parser;
	$obp->clearState();
	$obp->setTitle('');
	$obp->mOptions = new ParserOptions;
	$wiki_text = $obp->doAllQuotes($wiki_text);

	#Substitute ===
	$wiki_text=preg_replace('/(?:<p>|)\s*===(.*?)===\s*(?:<\/p>|)/','<h3>\1</h3>',$wiki_text);
	
	#Substitute ==
	$wiki_text=preg_replace('/(?:<p>|)\s*==(.*?)==\s*(?:<\/p>|)/','<h2>\1</h2>',$wiki_text);

	#Substitute [[Image:a]]
	if (!$wgHashedUploadDirectory) {
		$wiki_text=preg_replace('/\[\[Image:(.*?)\]\]/','<img alt="\1" src="' . $wgUploadPath . '/\1" />',$wiki_text);
	} else {
		$wiki_text = substitute_hashed_img_urls($wiki_text);
	}

	$wiki_text=preg_replace('/\[\[Image:(.*?)\]\]/','<img alt="\1" src="' . $wgUploadPath . '/\1" />',$wiki_text);

	#Create [[a|b]] syntax for every link
	#TODO: What to do for the [[word (detailed disambiguation)|]] 'pipe trick'?
	$wiki_text=preg_replace('/\[\[([^|]*?)\]\]/','[[\1|\1]]',$wiki_text);

	#Substitute [[ syntax (internal links)
	if (preg_match('/\[\[([^|\]]*?):(.*?)\|(.*?)\]\]/',$wiki_text,$unwanted_matches))
		return deny_visual_because_of(wfMsg('reason_special_link', $unwanted_matches[0]), $edit_context);
	#Preserve #section links from the draconic feature detection
	$wiki_text=preg_replace_callback('/\[\[(.*?)\|(.*?)\]\]/',
		create_function('$matches', 'return "[[".str_replace("#","__TEMP_MEAN_hash",$matches[1])."|".str_replace("#","__TEMP_MEAN_hash",$matches[2])."]]";'),
		$wiki_text);
	$wiki_text=preg_replace_callback('/<a href="(.*?)">/',
			create_function('$matches', 'return "<a href=\"".str_replace("#","__TEMP_MEAN_hash",$matches[1])."\">";'),
			$wiki_text);
	$wiki_text=preg_replace('/\[\[(.*?)\|(.*?)\]\]/','<a href="' . $meaneditor_page_src . '\1">\2</a>',$wiki_text);
	
	#Create [a b] syntax for every link
	#(must be here, so that internal links have already been replaced)
	$wiki_text=preg_replace('/\[([^| ]*?)\]/','[\1 _autonumber_]',$wiki_text);
	
	#Substitute [ syntax (external links)
	$wiki_text=preg_replace('/\[(.*?) (.*?)\]/','<a href="\1">\2</a>',$wiki_text);

	#Lists support
	$wiki_text=preg_replace("/<p># (.*?)<\/p>/",'<ol><li>\1</li></ol>',$wiki_text);
	$wiki_text=preg_replace("/<p>\* (.*?)<\/p>/",'<ul><li>\1</li></ul>',$wiki_text);
	$wiki_text=preg_replace("/<\/ol>\n<ol>/","\n",$wiki_text);
	$wiki_text=preg_replace("/<\/ul>\n<ul>/","\n",$wiki_text);


	# Crude but safe detection of unsupported features
	# In the future, this could be loosened a lot, should also detect harmless uses
	# TODO: Compare with MediaWiki security policy, ensure no mediawiki code can create unsafe HTML in the editor

	# Allow numbered entities, these occur far too often and should be innocous
	$wiki_text=str_replace('&#','__TEMP__MEAN__nument',$wiki_text);
	
	$unwanted_chars = array('[', ']', '|', '{', '}', '#', '*');
	foreach ($unwanted_chars as $uc)
		if (!($unwanted_match = strpos($wiki_text, $uc) === FALSE))
			return deny_visual_because_of(wfMsg('reason_forbidden_char', $uc), $edit_context);

	# Restore numbered entities
	$wiki_text=str_replace('__TEMP__MEAN__nument','&#',$wiki_text);

	#<ref> support
	global $refs_div;
	global $refs_num;
	$refs_div='';
	$refs_num=0;
	$wiki_text=preg_replace_callback('/<ref>(.*?)<\/ref>/',
			create_function('$matches', 'global $refs_div,$refs_num; $refs_num++; $refs_div=$refs_div."<p id=ref".$refs_num." class=\"ref\"> [".$refs_num."] ".
				$matches[1]."</p>"; return "<a href=\"#ref".$refs_num."\"> [".$refs_num."] </a>";'),
			$wiki_text);
	$refs_div='<div class="ref">'.$refs_div."</div>";

	
	# We saved #section links from the sacred detection fury, now restore them
	$wiki_text=str_replace("__TEMP_MEAN_hash","#",$wiki_text);

	$wiki_text=$wiki_text.$refs_div;
	
	return false;
}

function meaneditor_html2wiki($article, $user, &$edit_context, &$html_text)
{
	global $wgArticlePath;
	$meaneditor_page_src = str_replace('$1', '', $wgArticlePath);
	$meaneditor_page_src_escaped = addcslashes($meaneditor_page_src, '/.');
	
	$html_text=preg_replace('/(^|\n)<hr \/>*/', '\\1-----',$html_text);
	$html_text=preg_replace('/<strong>(.*?)<\/strong>/','\'\'\'\1\'\'\'',$html_text);
	$html_text=preg_replace('/<em>(.*?)<\/em>/','\'\'\1\'\'',$html_text);
	$html_text=preg_replace('/<h2>(.*?)<\/h2>/',"==\\1==\n",$html_text);
	$html_text=preg_replace('/<h3>(.*?)<\/h3>/',"===\\1===\n",$html_text);
	
	$html_text=preg_replace_callback('/<a href="'.$meaneditor_page_src_escaped.'(.*?)">/',
		create_function('$matches', 'return "<a href=\"'.$meaneditor_page_src.'" . rawurldecode($matches[1]). "\">";'), $html_text);
	$html_text=preg_replace('/<a href="'.$meaneditor_page_src_escaped.'(.*?)">(.*?)<\/a>/','[[\1|\2]]',$html_text);

	$html_text=preg_replace('/references_here/','<references />',$html_text);


	#<ref> support:
	# 1) Extract references block
	global $html_refs;
	$html_text=preg_replace_callback('/<div class="ref">(.*?)<\/div>/',
			create_function('$matches', 'global $html_refs; $html_refs=$matches[1]; 
				return "";'),
			$html_text);
	# 2) Put each reference in place
	$html_text=preg_replace_callback('/<a href="#(.*?)">.*?<\/a>/',
		create_function('$matches', 'global $html_refs; preg_match("/<p id=.".$matches[1].".*?> \[.*?\] (.*?)<\/p>/",$html_refs,$b);return "<ref>".$b[1]."</ref>";'),$html_text);
	

	$html_text=preg_replace('/<p>/','',$html_text);
	$html_text=preg_replace('/<\/p>/',"\n\n",$html_text);

	
	$html_text=preg_replace('/<a href="(.*?)">(.*?)<\/a>/','[\1 \2]',$html_text);
	
	$html_text=preg_replace_callback('/<img alt="(.*?)" src="(.*?)" \/>/',create_function('$matches',
		'return "[[Image:".$matches[1]."]]";'
	),$html_text);
	$html_text=preg_replace_callback('/<img src="(.*?)" alt="(.*?)" \/>/',create_function('$matches',
		'return "[[Image:".$matches[2]."]]";'
	),$html_text);


	# TODO: integrate lists with the previous paragraph? Check XHTML requirements
	$html_text=preg_replace_callback('/<ol>(.*?)<\/ol>/',create_function('$matches',
		'$matches[1]=str_replace("<li>","# ",$matches[1]);
		return str_replace("</li>","\n",$matches[1])."\n";'),$html_text);
	$html_text=preg_replace_callback('/<ul>(.*?)<\/ul>/',create_function('$matches',
		'$matches[1]=str_replace("<li>","* ",$matches[1]);
		return str_replace("</li>","\n",$matches[1])."\n";'),$html_text);


	# Let's simplify [page] links which don't need [page|text] syntax
	$html_text=preg_replace('/\[\[(.*?)\|\1\]\]/','[[\1]]',$html_text);
	# The same for autonumbered external links
	$html_text=preg_replace('/\[(.*?) _autonumber_\]/','[\1]',$html_text);
	
	
	# Safe-guard against unwanted whitespace at the beginning of a line
	# TODO: code sections
	$html_text=preg_replace('/^[ \t]+/',"",$html_text);
	$html_text=preg_replace('/\n[ \t]+/',"\n",$html_text);

	# When editing sections, Wymeditor has the bad habit of adding two newlines
	# TODO: Why? Anyway, redundant whitespace handling is already authoritarian
	$html_text=preg_replace('/\n\n$/', '', $html_text);

	return false;
}

function meaneditor_showBox(&$edit_context, $html_text, $rows, $cols, $ew)
{
	global $wgOut, $wgArticlePath, $wgStylePath, $wgUploadPath, $wgLang;
	wfLoadExtensionMessages('MeanEditor');
	$sk = new Skin;
	$wiki_path = str_replace('$1', '', $wgArticlePath);
	$wgOut->addScriptFile('../../extensions/MeanEditor/wymeditor/jquery/jquery.js');
	$wgOut->addScriptFile('../../extensions/MeanEditor/wymeditor/wymeditor/jquery.wymeditor.pack.js');
	$wgOut->addScriptFile('../../extensions/MeanEditor/wymeditor/wymeditor/plugins/resizable/jquery.wymeditor.resizable.js');
	$wgOut->addExtensionStyle('../extensions/MeanEditor/fix_meaneditor.css');
	
	# For now, it looks better in IE8 standards mode, even though IE support is very messy
	#$wgOut->addMeta('X-UA-Compatible', 'IE=7');
	
	$wgOut->addInlineScript('
			Array.prototype.wym_remove = function(from, to) {
				// From a suggestion at forum.wymeditor.org
				this.splice(from, !to || 1 + to - from + (!(to < 0 ^ from >= 0) && (to < 0 || -1) * this.length));
				    return this.length;
			};
	                jQuery(function() {
	                    jQuery(\'.wymeditor\').wymeditor({
					html: "'.addcslashes($html_text,"\"\n").'",
					lang: "'.$wgLang->getCode().'",
					iframeBasePath: "extensions/MeanEditor/iframe/",
					dialogLinkHtml: "<body class=\'wym_dialog wym_dialog_link\'"
						+ " onload=\'WYMeditor.INIT_DIALOG(" + WYMeditor.INDEX + ")\'"
						+ ">"
						+ "<form>"
						+ "<fieldset>"
						+ "<input type=\'hidden\' class=\'wym_dialog_type\' value=\'"
						+ WYMeditor.DIALOG_LINK
						+ "\' />"
						+ "<legend>{Link}</legend>"
						+ "<div class=\'row\'>"
						+ "<label>{URL}</label>"
						+ "<input type=\'text\' class=\'wym_href\' value=\'\' size=\'40\' />"
						+ "</div>"
						+ "<div class=\'row row-indent\'>"
						+ "<input class=\'wym_submit\' type=\'button\'"
						+ " value=\'{Submit}\' />"
						+ "<input class=\'wym_cancel\' type=\'button\'"
						+ "value=\'{Cancel}\' />"
						+ "</div>"
						+ "</fieldset>"
						+ "</form>"
						+ "</body>",
					dialogImageHtml:  "<body class=\'wym_dialog wym_dialog_image\'"
						+ " onload=\'WYMeditor.INIT_DIALOG(" + WYMeditor.INDEX + ")\'"
						+ ">' . preg_replace('/[\r\n]+/', "", str_replace('</script>','</scr"+"ipt>',str_replace('"','\\"',str_replace('\'','\\\'',$sk->makeGlobalVariablesScript(false))))) . '"
						+ "<script type=\'text/javascript\' src=\''.$wgStylePath.'/common/ajax.js\'></scr"+"ipt>"
						+ "<script type=\'text/javascript\'>function meaneditor_responder(e) {"
						+ "	divwait=document.getElementById(\'meaneditor_ajax_wait\');"
						+ "	if (divwait)"
						+ "		divwait.style.display = \'none\';"
						+ "	div=document.getElementById(\'meaneditor_ajax_table\');"
						+ "	div.innerHTML=e.responseText;"
						+ "}</scr"+"ipt>"
						+ "<form>"
						+ "<fieldset>"
						+ "<input type=\'hidden\' class=\'wym_dialog_type\' value=\'"
						+ WYMeditor.DIALOG_IMAGE
						+ "\' />"
						+ "<legend>{Image}</legend>"
						+ "<div class=\'row\'>"
						+ "<label>{Title}</label>"
						+ "<input id=\'image_name\' type=\'text\' class=\'wym_src\' value=\'\' size=\'40\' />"
						+ "</div>"
						+ "<div class=\'row\'>"
						+ "<script>sajax_do_call(\'recent_images\',[0],meaneditor_responder,0);</scr"+"ipt>"
						+ "<p>' . wfMsg('recent_images_text',str_replace('$1','Special:Upload',$wgArticlePath)) . '</p>"
						+ "<div id=\'meaneditor_ajax_wait\' style=\'color: #999; margin-bottom: 1em;\'>' . wfMsg('livepreview-loading') . '</div>"
						+ "<div style=\'max-height: 115px; overflow: auto\'><table id=\'meaneditor_ajax_table\'></table></div>"
						+ "</div>"
						+ "<div class=\'row row-indent\'>"
						+ "<input class=\'wym_submit_meaneditor_image\' type=\'button\'"
						+ " value=\'{Submit}\' />"
						+ "<input class=\'wym_cancel\' type=\'button\'"
						+ "value=\'{Cancel}\' />"
						+ "</div>"
						+ "</fieldset>"
						+ "</form>"
						+ "</body>",

					preInit: function(wym) {
						// Remove unwanted buttons, code from a suggestion at forum.wymeditor.org
						wym._options.toolsItems.wym_remove(6);
						wym._options.toolsItems.wym_remove(6);
						wym._options.toolsItems.wym_remove(11);
						wym._options.toolsItems.wym_remove(12);
						wym._options.toolsItems.wym_remove(12);
					},
					postInit: function(wym) {
						var wikilink_button_html = "<li class=\'wym_tools_wikilink\'>"
							+ "<a name=\'Wikilink\' href=\'#\' "
							+ "style=\'background-image: url(extensions/MeanEditor/wikilink-icon.png)\'>"
							+ "Create Wikilink</a></li>";
						var wikilink_dialog_html = "<body class=\'wym_dialog wym_dialog_wikilink\'"
							+ " onload=\'WYMeditor.INIT_DIALOG(" + WYMeditor.INDEX + ")\'"
							+ ">"
							+ "<form>"
							+ "<fieldset>"
							+ "<input type=\'hidden\' class=\'wym_dialog_type\' value=\'"
							+ "MeanEditor_dialog_wikilink"
							+ "\' />"
							+ "<legend>Wikilink</legend>"
							+ "<div class=\'row\'>"
							+ "<label>Page</label>"
							+ "<input type=\'text\' class=\'wym_wikititle\' value=\'\' size=\'40\' />"
							+ "</div>"
							+ "<div class=\'row row-indent\'>"
							+ "Tip: to link \"dog\" from \"dogs\", just select the first letters."
							+ "</div>"
							+ "<div class=\'row row-indent\'>"
							+ "<input class=\'wym_submit wym_submit_wikilink\' type=\'button\'"
							+ " value=\'{Submit}\' />"
							+ "<input class=\'wym_cancel\' type=\'button\'"
							+ "value=\'{Cancel}\' />"
							+ "</div></fieldset></form></body>";

						jQuery(wym._box).find(wym._options.toolsSelector + wym._options.toolsListSelector)
							.append(wikilink_button_html);
						jQuery(wym._box).find(\'li.wym_tools_wikilink a\').click(function() {
							wym.dialog(\'Wikilink\', wikilink_dialog_html);
							return (false);
						});

						wym.resizable();
					},
					preInitDialog: function(wym, wdm) {
						if (wdm.jQuery(wym._options.dialogTypeSelector).val() != \'MeanEditor_dialog_wikilink\')
							return;

						var selected = wym.selected();

						// Copied from Link dialog handling
						if(selected && selected.tagName && selected.tagName.toLowerCase != WYMeditor.A)
							selected = jQuery(selected).parentsOrSelf(WYMeditor.A);
						if(!selected && wym._selected_image)
							selected = jQuery(wym._selected_image).parentsOrSelf(WYMeditor.A);

						var wikipage;
						wikipage = jQuery(selected).attr(WYMeditor.HREF);
						if (wikipage) {
							if (wikipage.indexOf(\'' . $wiki_path . '\') == -1) {
								alert(\'This is an external link. If you want to convert it to a wikilink, remove the existing link first.\');
								wikipage = \'[External link, do not edit here]\';
								wdm.close();
							}
							else wikipage = wikipage.slice(' . strlen($wiki_path) . ');
						} else if (wym._iframe.contentWindow.getSelection) {
							wikipage = wym._iframe.contentWindow.getSelection().toString();
						} else if (wym._iframe.contentWindow.document.selection && wym._iframe.contentWindow.document.selection.createRange) {
							var range = wym._iframe.contentWindow.document.selection.createRange();
							wikipage = range.text;
						}
						wdm.jQuery(\'.wym_wikititle\').val(wikipage);
					},
					postInitDialog: function(wym, wdw) {
						var dbody = wdw.document.body;
						wdw.jQuery(dbody).find(\'input.wym_submit_wikilink\').click(function() {
							var wikipage = jQuery(dbody).find(\'.wym_wikititle\').val();
							var sUrl = \'' . $wiki_path . '\' + wikipage;

							// Copied from Link dialog handling
							var sStamp = wym.uniqueStamp();
							if(sUrl.length > 0) {
								wym._exec(WYMeditor.CREATE_LINK, sStamp);
								jQuery("a[@href=" + sStamp + "]", wym._doc.body)
									.attr(WYMeditor.HREF, sUrl);
							}
							wdw.close();
						});
						wdw.jQuery(dbody).find(\'input.wym_submit_meaneditor_image\').click(function() {
							var image_name = jQuery(dbody).find(wym._options.srcSelector).val();
							var sUrl = \'' . $wgUploadPath . '/' . '\' + image_name;

							// Copied from original dialog handling
							var sStamp = wym.uniqueStamp();
							if(sUrl.length > 0) {
								wym._exec(WYMeditor.INSERT_IMAGE, sStamp);
								jQuery("img[@src=" + sStamp + "]", wym._doc.body)
									.attr(WYMeditor.SRC, sUrl)
									.attr(WYMeditor.ALT, image_name);
							}
							wdw.close();
						});
					}

	                    });
	                });
	');
	$wgOut->addHTML( <<<END
<textarea tabindex='1' accesskey="," name="wpTextbox1" id="wpTextbox1" class="wymeditor" rows='{$rows}'
cols='{$cols}'{$ew}></textarea>
END
	);
	return false;
}

function recent_images($rsargs) 
{
	global $wgUploadPath, $wgDBprefix;

	$u = User::newFromSession();
	$dbw =& wfGetDB( DB_MASTER );
	$res=$dbw->query('select img_name from '.$wgDBprefix.'image where img_user='.$u->getId().';');
	$return_text='';
	$return_empty = true;
	for($i=0;$i<$res->numRows();$i++)
	{
		$ret=$res->fetchRow();
		$return_text=$return_text.'<tr><td><img src="'.$wgUploadPath.'/'.$ret['img_name'].'" height="100px" width="100px" onclick="n=document.getElementById(\'image_name\'); n.value=\''.$ret['img_name'].'\';" /></td></tr><tr><td>'.$ret['img_name'].'</td></tr>';
		$return_empty = false;
	}
	if ($return_empty) {
		wfLoadExtensionMessages('MeanEditor');
		return '<tr><td colspan="2"><strong>' . wfMsgWikiHtml('no_recent_images') . '</strong>' . ($u->isLoggedIn() ? '' : wfMsgWikiHtml('try_login')) . '</td></tr>';
	} else return $return_text;
}
$wgAjaxExportList[] = "recent_images";

function toggle_visualeditor_preference(&$toggles)
{
	$toggles[] = 'prefer_traditional_editor';
	return false;
}

require_once $IP . "/includes/EditPage.php";
require_once "MeanEditorEditPage.body.php";
function meaneditor_customeditor($article, $user)
{
	$editor = new MeanEditorEditPage( $article );
	$editor->submit();
	
	return false;
}

$wgHooks['UserToggles'][] = 'toggle_visualeditor_preference';


# Regular Editpage hooks
function meaneditor_checkboxes(&$editpage, &$checkboxes, &$tabindex)
{
	wfLoadExtensionMessages('MeanEditor');
	$checkboxes['want_traditional_editor'] = '';
	$attribs = array(
		'tabindex'  => ++$tabindex,
		#TODO: 'accesskey' => wfMsg( 'accesskey-minoredit' ),
		'id'        => 'wpWantTraditionalEditor',
	);
	$checkboxes['want_traditional_editor'] =
		Xml::check( 'wpWantTraditionalEditor', $editpage->userWantsTraditionalEditor, $attribs ) .
		"&nbsp;<label for='wpWantTraditionalEditor'>" . wfMsg('checkbox_force_traditional') . "</label>";
	return true;
}

function meaneditor_disabletoolbar(&$toolbar)
{
	$toolbar = '';
	return false;
}

$wgHooks['EditPage::wiki2html'][] = 'meaneditor_wiki2html';
$wgHooks['EditPage::html2wiki'][] = 'meaneditor_html2wiki';
$wgHooks['EditPage::showBox'][] = 'meaneditor_showBox';
$wgHooks['CustomEditor'][] = 'meaneditor_customeditor';
$wgHooks['EditPageBeforeEditChecks'][] = 'meaneditor_checkboxes';
$wgHooks['EditPageBeforeEditToolbar'][] = 'meaneditor_disabletoolbar';
