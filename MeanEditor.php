<?php
# Alert the user that this is not a valid entry point to MediaWiki if they try to access the skin file directly.
if (!defined('MEDIAWIKI') || $wgHashedUploadDirectory)
{
	echo <<<EOT
	To install the MeanEditor extension disable wgHashedUploadDirectory and put the following line in LocalSettings.php:
	require_once( "$IP/extensions/MeanEditor/MeanEditor.php" );
	
	See README-MeanEditor.txt for more information (if your wiki is not reached through /mediawiki you will need to make some changes).
EOT;
	         exit( 1 );
}

$wgExtensionMessagesFiles['MeanEditor'] = dirname(__FILE__) . '/MeanEditor.i18n.php';
$wgExtensionCredits['other'][] = array(
	'name' => 'MeanEditor',
	'author' => 'Jacopo Corbetta and Alessandro Pignotti for Antonio Gulli',
	'description' => 'The mean, the safe and the ugly visual editor for non-technical users. Based on WYMeditor and jQuery.',
	'descriptionmsg' => 'meaneditor_desc',
	'url' => 'http://www.mediawiki.org/wiki/Extension:MeanEditor'
);


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
		
	# Detect custom tags: only <br /> and references are supported at the moment
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
	if (!((strpos($wiki_text, '<')===FALSE) && (strpos($wiki_text, '>')===FALSE)))
		return deny_visual_because_of(wfMsg('reason_tag'), $edit_context);
	$wiki_text=str_replace('__TEMP__TEMP__br','<br />', $wiki_text);
	$wiki_text=str_replace('__TEMP__TEMP__allreferences','references_here',$wiki_text);
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
	#FIXME: do not require $wgHashedUploadDirectory	= false
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


	# TODO: integrate lists with the previous paragraph? Check XHTML requirements
	$html_text=preg_replace_callback('/<ol>(.*?)<\/ol>/',create_function('$matches',
		'$matches[1]=str_replace("<li>","# ",$matches[1]);
		return str_replace("</li>","",$matches[1])."\n";'),$html_text);
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
	global $wgOut;
	$wgOut->addHtml('<link rel="stylesheet" type="text/css" media="screen" href="extensions/MeanEditor/wymeditor/styles.css" />
	                 <link rel="stylesheet" type="text/css" media="screen" href="extensions/MeanEditor/wymeditor/skins/default/screen.css" />
	                 <script type="text/javascript" src="extensions/MeanEditor/jquery/jquery.js"></script>
	                 <script type="text/javascript" src="extensions/MeanEditor/wymeditor/jquery.wymeditor.pack.js.php"></script>');
	$wgOut->addHtml('<script type="text/javascript">
	                function responder(e)
	                {
					    div=document.getElementById(\'context_table\');
	                    div.innerHTML=e.responseText;
	                }
	                jQuery(function() {
	                    jQuery(\'.wymeditor\').wymeditor({
	                                html: "'.addcslashes($html_text,"\"\n").'",
	                                stylesheet: \'styles.css\'
	                    });
	                });
	                </script>');
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
#		$return_text=$return_text.'<tr><td><img src="'.$wgUploadPath.'/'.$ret['img_name'].'" height=100% width=100% onclick="div=document.getElementById(\'image_name\'); div.value=\''.$ret['img_name']+'\';" /></td></tr>';
		$return_text=$return_text.'<tr><td><img src="'.$wgUploadPath.'/'.$ret['img_name'].'" height=100% width=100% onclick="div=document.getElementById(\'image_name\'); div.value=\''.$ret['img_name'].'\';" /></td></tr><tr><td>'.$ret['img_name'].'</td></tr>';
		$return_empty = false;
	}
	if ($return_empty) {
		wfLoadExtensionMessages('MeanEditor');
		return '<tr><td colspan="2"><strong>' . wfMsgWikiHtml('no_recent_images') . '</strong>' . ($u->isLoggedIn() ? '' : '<br />'. wfMsgWikiHtml('try_login')) . '</td></tr>';
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
		Xml::check( 'wpWantTraditionalEditor', $checked['want_traditional_editor'], $attribs ) .
		"&nbsp;<label for='wpWantTraditionalEditor'>" . wfMsg('checkbox_force_traditional') . "</label>";
	return true;
}



$wgHooks['EditPage::wiki2html'][] = 'meaneditor_wiki2html';
$wgHooks['EditPage::html2wiki'][] = 'meaneditor_html2wiki';
$wgHooks['EditPage::showBox'][] = 'meaneditor_showBox';
$wgHooks['CustomEditor'][] = 'meaneditor_customeditor';
$wgHooks['EditPageBeforeEditChecks'][] = 'meaneditor_checkboxes';
