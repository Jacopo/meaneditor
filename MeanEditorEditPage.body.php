<?php
/**
 * Unfortunately, we have to override entire methods of EditPage
 * Search for "MeanEditor" to find our patches
 *
 * The situation has improved a lot since Mediawiki 1.16, thanks guys!
 *
 */

class MeanEditorEditPage extends EditPage {
	# MeanEditor: set when we decide to revert to traditional editing
	var $noVisualEditor = false;
	# MeanEditor: respect user preference
	var $userWantsTraditionalEditor = false;

	/**
	 * Replace entire edit method, need to convert HTML -> wikitext before saving 
	 *
	 * Perhaps one of the new hooks could do we need? But what about the interaction
	 * with other extensions?
	 */
	 function edit() {
		global $wgOut, $wgRequest, $wgUser;

		# MeanEditor: enabling this hook without also disabling visual editing
		#             would probably create a mess
		#// Allow extensions to modify/prevent this form or submission
		#if ( !wfRunHooks( 'AlternateEdit', array( $this ) ) ) {
		#	return;
		#}

		wfProfileIn( __METHOD__ );
		wfDebug( __METHOD__.": enter\n" );

		// This is not an article
		$wgOut->setArticleFlag( false );

		$this->importFormData( $wgRequest );
		$this->firsttime = false;

		if ( $this->live ) {
			$this->livePreview();
			wfProfileOut( __METHOD__ );
			return;
		}

		if ( wfReadOnly() && $this->save ) {
			// Force preview
			$this->save = false;
			$this->preview = true;
		}

		$wgOut->addScriptFile( 'edit.js' );

		if ( $wgUser->getOption( 'uselivepreview', false ) ) {
			$wgOut->includeJQuery();
			$wgOut->addScriptFile( 'preview.js' );
		}
		// Bug #19334: textarea jumps when editing articles in IE8
		$wgOut->addStyle( 'common/IE80Fixes.css', 'screen', 'IE 8' );

		$permErrors = $this->getEditPermissionErrors();
		if ( $permErrors ) {
			wfDebug( __METHOD__ . ": User can't edit\n" );
			$this->readOnlyPage( $this->getContent( false ), true, $permErrors, 'edit' );
			wfProfileOut( __METHOD__ );
			return;
		} else {
			if ( $this->save ) {
				$this->formtype = 'save';
			} else if ( $this->preview ) {
				$this->formtype = 'preview';
			} else if ( $this->diff ) {
				$this->formtype = 'diff';
			} else { # First time through
				$this->firsttime = true;
				if ( $this->previewOnOpen() ) {
					$this->formtype = 'preview';
				} else {
					$this->formtype = 'initial';
				}
			}
		}

		// If they used redlink=1 and the page exists, redirect to the main article
		if ( $wgRequest->getBool( 'redlink' ) && $this->mTitle->exists() ) {
			$wgOut->redirect( $this->mTitle->getFullURL() );
		}

		wfProfileIn( __METHOD__."-business-end" );

		$this->isConflict = false;
		// css / js subpages of user pages get a special treatment
		$this->isCssJsSubpage      = $this->mTitle->isCssJsSubpage();
		$this->isCssSubpage        = $this->mTitle->isCssSubpage();
		$this->isJsSubpage         = $this->mTitle->isJsSubpage();
		$this->isValidCssJsSubpage = $this->mTitle->isValidCssJsSubpage();

		# Show applicable editing introductions
		if ( $this->formtype == 'initial' || $this->firsttime )
			$this->showIntro();

		if ( $this->mTitle->isTalkPage() ) {
			$wgOut->addWikiMsg( 'talkpagetext' );
		}

		# Optional notices on a per-namespace and per-page basis
		$editnotice_ns   = 'editnotice-'.$this->mTitle->getNamespace();
		if ( !wfEmptyMsg( $editnotice_ns, wfMsgForContent( $editnotice_ns ) ) ) {
			$wgOut->addWikiText( wfMsgForContent( $editnotice_ns )  );
		}
		if ( MWNamespace::hasSubpages( $this->mTitle->getNamespace() ) ) {
			$parts = explode( '/', $this->mTitle->getDBkey() );
			$editnotice_base = $editnotice_ns;
			while ( count( $parts ) > 0 ) {
				$editnotice_base .= '-'.array_shift( $parts );
				if ( !wfEmptyMsg( $editnotice_base, wfMsgForContent( $editnotice_base ) ) ) {
					$wgOut->addWikiText( wfMsgForContent( $editnotice_base )  );
				}
			}
		}


		# MeanEditor: always use traditional editing for these strange things
		if ($this->mTitle->getNamespace() != NS_MAIN || $this->isCssJsSubpage)
			$this->noVisualEditor = true;

		# MeanEditor: convert HTML to wikitext
		#             The hidden box should tell us if the editor was in use (we got HTML in the POST)
		if ( !$this->firsttime && !($this->formtype == 'initial') && !$this->noVisualEditor ) {
			wfRunHooks('EditPage::html2wiki', array($this->mArticle, $wgUser, &$this, &$this->textbox1));
		}

		# MeanEditor: we could leave MeanEditor enabled, but I think it would be confusing
		if ($this->diff || ($this->formtype == 'diff'))
			$this->noVisualEditor = true;




		# Attempt submission here.  This will check for edit conflicts,
		# and redundantly check for locked database, blocked IPs, etc.
		# that edit() already checked just in case someone tries to sneak
		# in the back door with a hand-edited submission URL.

		if ( 'save' == $this->formtype ) {
			if ( !$this->attemptSave() ) {
				wfProfileOut( __METHOD__."-business-end" );
				wfProfileOut( __METHOD__ );
				return;
			}
		}

		# First time through: get contents, set time for conflict
		# checking, etc.
		if ( 'initial' == $this->formtype || $this->firsttime ) {
			if ( $this->initialiseForm() === false ) {
				$this->noSuchSectionPage();
				wfProfileOut( __METHOD__."-business-end" );
				wfProfileOut( __METHOD__ );
				return;
			}
			if ( !$this->mTitle->getArticleId() )
				wfRunHooks( 'EditFormPreloadText', array( &$this->textbox1, &$this->mTitle ) );
			else
				wfRunHooks( 'EditFormInitialText', array( $this ) );
		}

		$this->showEditForm();
		wfProfileOut( __METHOD__."-business-end" );
		wfProfileOut( __METHOD__ );
	}




	/**
	 * We need to read the checkbox and the hidden value to know if the
	 * visual editor was used or not
	 */
	function importFormData( &$request ) {
		global $wgUser;

		if ( $request->wasPosted() ) {
			# Reuse values from the previous submission
			$this->noVisualEditor = $request->getVal( 'wpNoVisualEditor' );
			$this->userWantsTraditionalEditor = $request->getCheck( 'wpWantTraditionalEditor' );
		} else {
			# Default values
			$this->noVisualEditor = false;
			$this->userWantsTraditionalEditor = $wgUser->getOption('prefer_traditional_editor');
		}

		return parent::importFormData($request);
	}

	# Mediawiki 1.16 implements almost exactly the hook we need here.
	# They even automatically disable the visual editor on conflicts. Thanks guys!
	function showContentForm() {
		global $wgOut;

		# Should be redundant, but check just in case
		if ( $this->diff || wfReadOnly() ) {
			$this->noVisualEditor = true;
		}

		# Also apply htmlspecialchars? See $encodedtext
		$html_text = $this->safeUnicodeOutput( $this->textbox1 );
		if (!($this->noVisualEditor || $this->userWantsTraditionalEditor)) {
			$this->noVisualEditor = wfRunHooks('EditPage::wiki2html', array($this->mArticle, $wgUser, &$this, &$html_text));
		}
		if (!$this->noVisualEditor && !$this->userWantsTraditionalEditor) {
			# TODO: Now that MediaWiki has showContentForm, there is no need for a separate hook
			$this->noVisualEditor = wfRunHooks('EditPage::showBox', array(&$this, $html_text, $rows, $cols, $ew));
		}
		if (!$this->noVisualEditor && !$this->userWantsTraditionalEditor) {
			$wgOut->addHTML("<input type='hidden' value=\"0\" name=\"wpNoVisualEditor\" />\n");
		} else {
			$wgOut->addHTML("<input type='hidden' value=\"1\" name=\"wpNoVisualEditor\" />\n");
			parent::showContentForm();
	        }
	}
	
	# We need to set the correct value for our checkbox
	function showStandardInputs( &$tabindex = 2 ) {
		global $wgOut, $wgUser;
		$wgOut->addHTML( "<div class='editOptions'>\n" );

		if ( $this->section != 'new' ) {
			$this->showSummaryInput( false, $this->summary );
			$wgOut->addHTML( $this->getSummaryPreview( false, $this->summary ) );
		}

		# MeanEditor: also set the value of our checkbox
		$checkboxes = $this->getCheckboxes( $tabindex, $wgUser->getSkin(),
			array( 'minor' => $this->minoredit, 'watch' => $this->watchthis,
			 'want_traditional_editor' => $this->userWantsTraditionalEditor) );
			
		$wgOut->addHTML( "<div class='editCheckboxes'>" . implode( $checkboxes, "\n" ) . "</div>\n" );
		$wgOut->addHTML( "<div class='editButtons'>\n" );
		$wgOut->addHTML( implode( $this->getEditButtons( $tabindex ), "\n" ) . "\n" );

		$cancel = $this->getCancelLink();
		$separator = wfMsgExt( 'pipe-separator' , 'escapenoentities' );
		$edithelpurl = Skin::makeInternalOrExternalUrl( wfMsgForContent( 'edithelppage' ) );
		$edithelp = '<a target="helpwindow" href="'.$edithelpurl.'">'.
			htmlspecialchars( wfMsg( 'edithelp' ) ).'</a> '.
			htmlspecialchars( wfMsg( 'newwindow' ) );
		$wgOut->addHTML( "	<span class='editHelp'>{$cancel}{$separator}{$edithelp}</span>\n" );
		$wgOut->addHTML( "</div><!-- editButtons -->\n</div><!-- editOptions -->\n" );
	}

	
	# We need to add the class 'wymupdate' to all buttons
	public function getEditButtons(&$tabindex) {
		$buttons = array();

		$temp = array(
			'id'        => 'wpSave',
			'name'      => 'wpSave',
			'type'      => 'submit',
			'class'     => 'wymupdate', #MeanEditor
			'tabindex'  => ++$tabindex,
			'value'     => wfMsg( 'savearticle' ),
			'accesskey' => wfMsg( 'accesskey-save' ),
			'title'     => wfMsg( 'tooltip-save' ).' ['.wfMsg( 'accesskey-save' ).']',
		);
		$buttons['save'] = Xml::element('input', $temp, '');

		++$tabindex; // use the same for preview and live preview
		$temp = array(
			'id'        => 'wpPreview',
			'name'      => 'wpPreview',
			'type'      => 'submit',
			'class'     => 'wymupdate', #MeanEditor
			'tabindex'  => $tabindex,
			'value'     => wfMsg( 'showpreview' ),
			'accesskey' => wfMsg( 'accesskey-preview' ),
			'title'     => wfMsg( 'tooltip-preview' ) . ' [' . wfMsg( 'accesskey-preview' ) . ']',
		);
		$buttons['preview'] = Xml::element( 'input', $temp, '' );
		$buttons['live'] = '';

		$temp = array(
			'id'        => 'wpDiff',
			'name'      => 'wpDiff',
			'type'      => 'submit',
			'class'     => 'wymupdate', #MeanEditor
			'tabindex'  => ++$tabindex,
			'value'     => wfMsg( 'showdiff' ),
			'accesskey' => wfMsg( 'accesskey-diff' ),
			'title'     => wfMsg( 'tooltip-diff' ) . ' [' . wfMsg( 'accesskey-diff' ) . ']',
		);
		$buttons['diff'] = Xml::element( 'input', $temp, '' );

		wfRunHooks( 'EditPageBeforeEditButtons', array( &$this, &$buttons, &$tabindex ) );
		return $buttons;
	}


}
