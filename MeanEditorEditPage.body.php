<?php
/**
 * Unfortunately, we have to override entire methods of EditPage
 * Search for "MeanEditor" to find our patches
 */

class MeanEditorEditPage extends EditPage {
	# MeanEditor: set when we decide to revert to traditional editing
	var $noVisualEditor = false;
	# MeanEditor: respect user preference
	var $userWantsTraditionalEditor = false;

	/**
	 * Replace entire edit method, need to convert HTML -> wikitext before saving 
	 */
	function edit() {
		global $wgOut, $wgUser, $wgRequest;

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
		$permErrors = $this->getEditPermissionErrors();
		if ( $permErrors ) {
			wfDebug( __METHOD__.": User can't edit\n" );
			$this->readOnlyPage( $this->getContent(), true, $permErrors, 'edit' );
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
					$this->extractMetaDataFromArticle () ;
					$this->formtype = 'initial';
				}
			}
		}

		wfProfileIn( __METHOD__."-business-end" );

		$this->isConflict = false;
		// css / js subpages of user pages get a special treatment
		$this->isCssJsSubpage      = $this->mTitle->isCssJsSubpage();
		$this->isValidCssJsSubpage = $this->mTitle->isValidCssJsSubpage();

		# Show applicable editing introductions
		if ( $this->formtype == 'initial' || $this->firsttime )
			$this->showIntro();

		if ( $this->mTitle->isTalkPage() ) {
			$wgOut->addWikiMsg( 'talkpagetext' );
		}

		# Optional notices on a per-namespace and per-page basis
		$editnotice_ns   = 'editnotice-'.$this->mTitle->getNamespace();
		$editnotice_page = $editnotice_ns.'-'.$this->mTitle->getDBkey();
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
		} else if ( !wfEmptyMsg( $editnotice_page, wfMsgForContent( $editnotice_page ) ) ) {
			$wgOut->addWikiText( wfMsgForContent( $editnotice_page ) );
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
			if ( $this->initialiseForm() === false) {
				$this->noSuchSectionPage();
				wfProfileOut( __METHOD__."-business-end" );
				wfProfileOut( __METHOD__ );
				return;
			}
			if ( !$this->mTitle->getArticleId() )
				wfRunHooks( 'EditFormPreloadText', array( &$this->textbox1, &$this->mTitle ) );
		}

		$this->showEditForm();
		wfProfileOut( __METHOD__."-business-end" );
		wfProfileOut( __METHOD__ );
	}

	/**
	 * We need to read the hidden checkboxes to know if the
	 * visual editor was used or not
	 */
	function importFormData( &$request ) {
		if ( $request->wasPosted() ) {
			# MeanEditor: take note if the visual editor was used or not
			$this->noVisualEditor = $request->getVal( 'wpNoVisualEditor' );
			$this->userWantsTraditionalEditor = $request->getCheck( 'wpWantTraditionalEditor' );
		} else {
			$this->noVisualEditor = false;
			$this->userWantsTraditionalEditor = false;
		}

		return parent::importFormData($request);
	}

	/**
	 * Replace entire showEditForm, need to add our own textbox and stuff
	 */
	function showEditForm( $formCallback=null ) {
		global $wgOut, $wgUser, $wgLang, $wgContLang, $wgMaxArticleSize, $wgTitle, $wgRequest;

		# If $wgTitle is null, that means we're in API mode.
		# Some hook probably called this function  without checking
		# for is_null($wgTitle) first. Bail out right here so we don't
		# do lots of work just to discard it right after.
		if (is_null($wgTitle))
			return;

		$fname = 'EditPage::showEditForm';
		wfProfileIn( $fname );

		$sk = $wgUser->getSkin();

		wfRunHooks( 'EditPage::showEditForm:initial', array( &$this ) ) ;

		#need to parse the preview early so that we know which templates are used,
		#otherwise users with "show preview after edit box" will get a blank list
		#we parse this near the beginning so that setHeaders can do the title
		#setting work instead of leaving it in getPreviewText
		$previewOutput = '';
		if ( $this->formtype == 'preview' ) {
			$previewOutput = $this->getPreviewText();
		}

		$this->setHeaders();

		# Enabled article-related sidebar, toplinks, etc.
		$wgOut->setArticleRelated( true );

		if ( $this->isConflict ) {
			$wgOut->addWikiMsg( 'explainconflict' );

			$this->textbox2 = $this->textbox1;
			$this->textbox1 = $this->getContent();
			$this->edittime = $this->mArticle->getTimestamp();

			# MeanEditor: too complicated for visual editing
			$this->noVisualEditor = false;
		} else {
			if ( $this->section != '' && $this->section != 'new' ) {
				$matches = array();
				if ( !$this->summary && !$this->preview && !$this->diff ) {
					preg_match( "/^(=+)(.+)\\1/mi", $this->textbox1, $matches );
					if ( !empty( $matches[2] ) ) {
						global $wgParser;
						$this->summary = "/* " .
							$wgParser->stripSectionName(trim($matches[2])) .
							" */ ";
					}
				}
			}

			if ( $this->missingComment ) {
				$wgOut->wrapWikiMsg( '<div id="mw-missingcommenttext">$1</div>',  'missingcommenttext' );
			}

			if ( $this->missingSummary && $this->section != 'new' ) {
				$wgOut->wrapWikiMsg( '<div id="mw-missingsummary">$1</div>', 'missingsummary' );
			}

			if ( $this->missingSummary && $this->section == 'new' ) {
				$wgOut->wrapWikiMsg( '<div id="mw-missingcommentheader">$1</div>', 'missingcommentheader' );
			}

			if ( $this->hookError !== '' ) {
				$wgOut->addWikiText( $this->hookError );
			}

			if ( !$this->checkUnicodeCompliantBrowser() ) {
				$wgOut->addWikiMsg( 'nonunicodebrowser' );
			}
			if ( isset( $this->mArticle ) && isset( $this->mArticle->mRevision ) ) {
			// Let sysop know that this will make private content public if saved

				if ( !$this->mArticle->mRevision->userCan( Revision::DELETED_TEXT ) ) {
					$wgOut->addWikiMsg( 'rev-deleted-text-permission' );
				} else if ( $this->mArticle->mRevision->isDeleted( Revision::DELETED_TEXT ) ) {
					$wgOut->addWikiMsg( 'rev-deleted-text-view' );
				}

				if ( !$this->mArticle->mRevision->isCurrent() ) {
					$this->mArticle->setOldSubtitle( $this->mArticle->mRevision->getId() );
					$wgOut->addWikiMsg( 'editingold' );
				}
			}
		}

		if ( wfReadOnly() ) {
			$wgOut->wrapWikiMsg( "<div id=\"mw-read-only-warning\">\n$1\n</div>", array( 'readonlywarning', wfReadOnlyReason() ) );
			# MeanEditor: visual editing makes no sense here
			$this->noVisualEditor = true;
		} elseif ( $wgUser->isAnon() && $this->formtype != 'preview' ) {
			$wgOut->wrapWikiMsg( '<div id="mw-anon-edit-warning">$1</div>', 'anoneditwarning' );
		} else {
			if ( $this->isCssJsSubpage ) {
				# Check the skin exists
				if ( $this->isValidCssJsSubpage ) {
					if ( $this->formtype !== 'preview' ) {
						$wgOut->addWikiMsg( 'usercssjsyoucanpreview' );
					}
				} else {
					$wgOut->addWikiMsg( 'userinvalidcssjstitle', $wgTitle->getSkinFromCssJsSubpage() );
				}
			}
		}

		$classes = array(); // Textarea CSS
		if ( $this->mTitle->getNamespace() == NS_MEDIAWIKI ) {
			# Show a warning if editing an interface message
			$wgOut->wrapWikiMsg( "<div class='mw-editinginterface'>\n$1</div>", 'editinginterface' );
		} elseif ( $this->mTitle->isProtected( 'edit' ) ) {
			# Is the title semi-protected?
			if ( $this->mTitle->isSemiProtected() ) {
				$noticeMsg = 'semiprotectedpagewarning';
				$classes[] = 'mw-textarea-sprotected';
			} else {
				# Then it must be protected based on static groups (regular)
				$noticeMsg = 'protectedpagewarning';
				$classes[] = 'mw-textarea-protected';
			}
			$wgOut->addHTML( "<div class='mw-warning-with-logexcerpt'>\n" );
			$wgOut->addWikiMsg( $noticeMsg );
			LogEventsList::showLogExtract( $wgOut, 'protect', $this->mTitle->getPrefixedText(), '', 1 );
			$wgOut->addHTML( "</div>\n" );
		}
		if ( $this->mTitle->isCascadeProtected() ) {
			# Is this page under cascading protection from some source pages?
			list($cascadeSources, /* $restrictions */) = $this->mTitle->getCascadeProtectionSources();
			$notice = "<div class='mw-cascadeprotectedwarning'>$1\n";
			$cascadeSourcesCount = count( $cascadeSources );
			if ( $cascadeSourcesCount > 0 ) {
				# Explain, and list the titles responsible
				foreach( $cascadeSources as $page ) {
					$notice .= '* [[:' . $page->getPrefixedText() . "]]\n";
				}
			}
			$notice .= '</div>';
			$wgOut->wrapWikiMsg( $notice, array( 'cascadeprotectedwarning', $cascadeSourcesCount ) );
		}
		if ( !$this->mTitle->exists() && $this->mTitle->getRestrictions( 'create' ) ) {
			$wgOut->wrapWikiMsg( '<div class="mw-titleprotectedwarning">$1</div>', 'titleprotectedwarning' );
		}

		if ( $this->kblength === false ) {
			# MeanEditor: the length will probably be different in HTML
			$this->kblength = (int)(strlen( $this->textbox1 ) / 1024);
		}
		if ( $this->tooBig || $this->kblength > $wgMaxArticleSize ) {
			$wgOut->addHTML( "<div class='error' id='mw-edit-longpageerror'>\n" );
			$wgOut->addWikiMsg( 'longpageerror', $wgLang->formatNum( $this->kblength ), $wgLang->formatNum( $wgMaxArticleSize ) );
			$wgOut->addHTML( "</div>\n" );
		} elseif ( $this->kblength > 29 ) {
			$wgOut->addHTML( "<div id='mw-edit-longpagewarning'>\n" );
			$wgOut->addWikiMsg( 'longpagewarning', $wgLang->formatNum( $this->kblength ) );
			$wgOut->addHTML( "</div>\n" );
		}

		$q = 'action='.$this->action;
		#if ( "no" == $redirect ) { $q .= "&redirect=no"; }
		$action = $wgTitle->escapeLocalURL( $q );

		$summary = wfMsg( 'summary' );
		$subject = wfMsg( 'subject' );

		$cancel = $sk->makeKnownLink( $wgTitle->getPrefixedText(),
				wfMsgExt('cancel', array('parseinline')) );
		$separator = wfMsgExt( 'pipe-separator' , 'escapenoentities' );
		$edithelpurl = Skin::makeInternalOrExternalUrl( wfMsgForContent( 'edithelppage' ));
		$edithelp = '<a target="helpwindow" href="'.$edithelpurl.'">'.
			htmlspecialchars( wfMsg( 'edithelp' ) ).'</a> '.
			htmlspecialchars( wfMsg( 'newwindow' ) );

		global $wgRightsText;
		if ( $wgRightsText ) {
			$copywarnMsg = array( 'copyrightwarning',
				'[[' . wfMsgForContent( 'copyrightpage' ) . ']]',
				$wgRightsText );
		} else {
			$copywarnMsg = array( 'copyrightwarning2',
				'[[' . wfMsgForContent( 'copyrightpage' ) . ']]' );
		}

		if ( $wgUser->getOption('showtoolbar') and !$this->isCssJsSubpage ) {
			# prepare toolbar for edit buttons
			$toolbar = EditPage::getEditToolbar();
		} else {
			$toolbar = '';
		}

		// activate checkboxes if user wants them to be always active
		if ( !$this->preview && !$this->diff ) {
			# Sort out the "watch" checkbox
			if ( $wgUser->getOption( 'watchdefault' ) ) {
				# Watch all edits
				$this->watchthis = true;
			} elseif ( $wgUser->getOption( 'watchcreations' ) && !$this->mTitle->exists() ) {
				# Watch creations
				$this->watchthis = true;
			} elseif ( $this->mTitle->userIsWatching() ) {
				# Already watched
				$this->watchthis = true;
			}
			
			# May be overriden by request parameters
			if( $wgRequest->getBool( 'watchthis' ) ) {
				$this->watchthis = true;
			}

			if ( $wgUser->getOption( 'minordefault' ) ) $this->minoredit = true;

			# MeanEditor: User preference
			if( $wgUser->getOption( 'prefer_traditional_editor' ) ) $this->userWantsTraditionalEditor = true;
		}

		$wgOut->addHTML( $this->editFormPageTop );

		if ( $wgUser->getOption( 'previewontop' ) ) {
			$this->displayPreviewArea( $previewOutput, true );
		}

		$wgOut->addHTML( $this->editFormTextTop );

		# if this is a comment, show a subject line at the top, which is also the edit summary.
		# Otherwise, show a summary field at the bottom
		$summarytext = $wgContLang->recodeForEdit( $this->summary );

		# If a blank edit summary was previously provided, and the appropriate
		# user preference is active, pass a hidden tag as wpIgnoreBlankSummary. This will stop the
		# user being bounced back more than once in the event that a summary
		# is not required.
		#####
		# For a bit more sophisticated detection of blank summaries, hash the
		# automatic one and pass that in the hidden field wpAutoSummary.
		$summaryhiddens =  '';
		if ( $this->missingSummary ) $summaryhiddens .= Xml::hidden( 'wpIgnoreBlankSummary', true );
		$autosumm = $this->autoSumm ? $this->autoSumm : md5( $this->summary );
		$summaryhiddens .= Xml::hidden( 'wpAutoSummary', $autosumm );
		if ( $this->section == 'new' ) {
			$commentsubject = '';
			if ( !$wgRequest->getBool( 'nosummary' ) ) {
				$commentsubject =
					Xml::tags( 'label', array( 'for' => 'wpSummary' ), $subject );
				$commentsubject =
					Xml::tags( 'span', array( 'id' => 'wpSummaryLabel' ), $commentsubject );
				$commentsubject .= '&nbsp;';
				$commentsubject .= Xml::input( 'wpSummary',
									60,
									$summarytext,
									array(
										'id' => 'wpSummary',
										'maxlength' => '200',
										'tabindex' => '1'
									) );
			}
			$editsummary = "<div class='editOptions'>\n";
			global $wgParser;
			$formattedSummary = wfMsgForContent( 'newsectionsummary', $wgParser->stripSectionName( $this->summary ) );
			$subjectpreview = $summarytext && $this->preview ? "<div class=\"mw-summary-preview\">". wfMsg('subject-preview') . $sk->commentBlock( $formattedSummary, $this->mTitle, true )."</div>\n" : '';
			$summarypreview = '';
		} else {
			$commentsubject = '';

			$editsummary = Xml::tags( 'label', array( 'for' => 'wpSummary' ), $summary );
			$editsummary =
				Xml::tags( 'span', array( 'id' => 'wpSummaryLabel' ), $editsummary ) . ' ';
				
			$editsummary .= Xml::input( 'wpSummary',
				60,
				$summarytext,
				array(
					'id' => 'wpSummary',
					'maxlength' => '200',
					'tabindex' => '1'
				) );
			
			// No idea where this is closed.
			$editsummary = Xml::openElement( 'div', array( 'class' => 'editOptions' ) )
							. $editsummary . '<br/>';
				
			$summarypreview = '';
			if ( $summarytext && $this->preview ) {
				$summarypreview =
					Xml::tags( 'div',
						array( 'class' => 'mw-summary-preview' ),
						wfMsg( 'summary-preview' ) .
							$sk->commentBlock( $this->summary, $this->mTitle )
					);
			}
			$subjectpreview = '';
		}
		$commentsubject .= $summaryhiddens;


		# Set focus to the edit box on load, except on preview or diff, where it would interfere with the display
		if ( !$this->preview && !$this->diff ) {
			$wgOut->setOnloadHandler( 'document.editform.wpTextbox1.focus()' );
		}
		$templates = $this->getTemplates();
		$formattedtemplates = $sk->formatTemplates( $templates, $this->preview, $this->section != '');

		$hiddencats = $this->mArticle->getHiddenCategories();
		$formattedhiddencats = $sk->formatHiddenCategories( $hiddencats );

		global $wgUseMetadataEdit ;
		if ( $wgUseMetadataEdit ) {
			$metadata = $this->mMetaData ;
			$metadata = htmlspecialchars( $wgContLang->recodeForEdit( $metadata ) ) ;
			$top = wfMsgWikiHtml( 'metadata_help' );
			/* ToDo: Replace with clean code */
			$ew = $wgUser->getOption( 'editwidth' );
			if ( $ew ) $ew = " style=\"width:100%\"";
			else $ew = '';
			$cols = $wgUser->getIntOption( 'cols' );
			/* /ToDo */
			$metadata = $top . "<textarea name='metadata' rows='3' cols='{$cols}'{$ew}>{$metadata}</textarea>" ;
		}
		else $metadata = "" ;

		$recreate = '';
		if ( $this->wasDeletedSinceLastEdit() ) {
			if ( 'save' != $this->formtype ) {
				$wgOut->wrapWikiMsg(
					'<div class="error mw-deleted-while-editing">$1</div>',
					'deletedwhileediting' );
			} else {
				// Hide the toolbar and edit area, user can click preview to get it back
				// Add an confirmation checkbox and explanation.
				$toolbar = '';
				$recreate = '<div class="mw-confirm-recreate">' .
						$wgOut->parse( wfMsg( 'confirmrecreate',  $this->lastDelete->user_name , $this->lastDelete->log_comment ) ) .
						Xml::checkLabel( wfMsg( 'recreate' ), 'wpRecreate', 'wpRecreate', false,
							array( 'title' => $sk->titleAttrib( 'recreate' ), 'tabindex' => 1, 'id' => 'wpRecreate' )
						) . '</div>';
			}
		}

		$tabindex = 2;

		$checkboxes = $this->getCheckboxes( $tabindex, $sk,
			array( 'minor' => $this->minoredit, 'watch' => $this->watchthis, 'want_traditional_editor' => $this->userWantsTraditionalEditor ));

		$checkboxhtml = implode( $checkboxes, "\n" );

		$buttons = $this->getEditButtons( $tabindex );
		$buttonshtml = implode( $buttons, "\n" );

		$safemodehtml = $this->checkUnicodeCompliantBrowser()
			? '' : Xml::hidden( 'safemode', '1' );

		$wgOut->addHTML( <<<END
{$toolbar}
<form id="editform" name="editform" method="post" action="$action" enctype="multipart/form-data">
END
);

		if ( is_callable( $formCallback ) ) {
			call_user_func_array( $formCallback, array( &$wgOut ) );
		}

		wfRunHooks( 'EditPage::showEditForm:fields', array( &$this, &$wgOut ) );

		// Put these up at the top to ensure they aren't lost on early form submission
		$this->showFormBeforeText();

		$wgOut->addHTML( <<<END
{$recreate}
{$commentsubject}
{$subjectpreview}
{$this->editFormTextBeforeContent}
END
);

	if ( $this->isConflict || $this->diff ) {
		# MeanEditor: should be redundant, but let's be sure
		$this->noVisualEditor = true;
	}
	# MeanEditor: also apply htmlspecialchars? See $encodedtext
	$html_text = $this->safeUnicodeOutput( $this->textbox1 );
	if (!($this->noVisualEditor || $this->userWantsTraditionalEditor)) {
		$this->noVisualEditor = wfRunHooks('EditPage::wiki2html', array($this->mArticle, $wgUser, &$this, &$html_text));
	}
	if (!$this->noVisualEditor && !$this->userWantsTraditionalEditor) {
		$this->noVisualEditor = wfRunHooks('EditPage::showBox', array(&$this, $html_text, $rows, $cols, $ew));
	}
	if (!$this->noVisualEditor && !$this->userWantsTraditionalEditor) {
		$wgOut->addHTML("<input type='hidden' value=\"0\" name=\"wpNoVisualEditor\" />\n");
	} else {
		$wgOut->addHTML("<input type='hidden' value=\"1\" name=\"wpNoVisualEditor\" />\n");
		$this->showTextbox1( $classes );
        }

		$wgOut->wrapWikiMsg( "<div id=\"editpage-copywarn\">\n$1\n</div>", $copywarnMsg );
		$wgOut->addHTML( <<<END
{$this->editFormTextAfterWarn}
{$metadata}
{$editsummary}
{$summarypreview}
{$checkboxhtml}
{$safemodehtml}
END
);

		$wgOut->addHTML(
"<div class='editButtons'>
{$buttonshtml}
	<span class='editHelp'>{$cancel}{$separator}{$edithelp}</span>
</div><!-- editButtons -->
</div><!-- editOptions -->");

		/**
		 * To make it harder for someone to slip a user a page
		 * which submits an edit form to the wiki without their
		 * knowledge, a random token is associated with the login
		 * session. If it's not passed back with the submission,
		 * we won't save the page, or render user JavaScript and
		 * CSS previews.
		 *
		 * For anon editors, who may not have a session, we just
		 * include the constant suffix to prevent editing from
		 * broken text-mangling proxies.
		 */
		$token = htmlspecialchars( $wgUser->editToken() );
		$wgOut->addHTML( "\n<input type='hidden' value=\"$token\" name=\"wpEditToken\" />\n" );

		$this->showEditTools();

		$wgOut->addHTML( <<<END
{$this->editFormTextAfterTools}
<div class='templatesUsed'>
{$formattedtemplates}
</div>
<div class='hiddencats'>
{$formattedhiddencats}
</div>
END
);

		if ( $this->isConflict && wfRunHooks( 'EditPageBeforeConflictDiff', array( &$this, &$wgOut ) ) ) {
			$wgOut->wrapWikiMsg( '==$1==', "yourdiff" );

			$de = new DifferenceEngine( $this->mTitle );
			$de->setText( $this->textbox2, $this->textbox1 );
			$de->showDiff( wfMsg( "yourtext" ), wfMsg( "storedversion" ) );

			$wgOut->wrapWikiMsg( '==$1==', "yourtext" );
			$this->showTextbox2();
		}
		$wgOut->addHTML( $this->editFormTextBottom );
		$wgOut->addHTML( "</form>\n" );
		if ( !$wgUser->getOption( 'previewontop' ) ) {
			$this->displayPreviewArea( $previewOutput, false );
		}

		wfProfileOut( $fname );
	}


	/**
	 * For now, replace entire function
	 * FIXME: can this be written in a clean and sane way?
	 */
	public function getEditButtons(&$tabindex) {
		global $wgLivePreview, $wgUser;

		$buttons = array();

		$temp = array(
			'id'        => 'wpSave',
			'name'      => 'wpSave',
			'type'      => 'submit',
			# MeanEditor: should be harmless in any case
			'class'     => 'wymupdate',
			'tabindex'  => ++$tabindex,
			'value'     => wfMsg('savearticle'),
			'accesskey' => wfMsg('accesskey-save'),
			'title'     => wfMsg( 'tooltip-save' ).' ['.wfMsg( 'accesskey-save' ).']',
		);
		$buttons['save'] = Xml::element('input', $temp, '');

		++$tabindex; // use the same for preview and live preview
		if ( $wgLivePreview && $wgUser->getOption( 'uselivepreview' ) ) {
			$temp = array(
				'id'        => 'wpPreview',
				'name'      => 'wpPreview',
				'type'      => 'submit',
				# MeanEditor: should be harmless in any case
				'class'     => 'wymupdate',
				'tabindex'  => $tabindex,
				'value'     => wfMsg('showpreview'),
				'accesskey' => '',
				'title'     => wfMsg( 'tooltip-preview' ).' ['.wfMsg( 'accesskey-preview' ).']',
				'style'     => 'display: none;',
			);
			$buttons['preview'] = Xml::element('input', $temp, '');

			$temp = array(
				'id'        => 'wpLivePreview',
				'name'      => 'wpLivePreview',
				'type'      => 'submit',
				# MeanEditor: should be harmless in any case
				'class'     => 'wymupdate',
				'tabindex'  => $tabindex,
				'value'     => wfMsg('showlivepreview'),
				'accesskey' => wfMsg('accesskey-preview'),
				'title'     => '',
				'onclick'   => $this->doLivePreviewScript(),
			);
			$buttons['live'] = Xml::element('input', $temp, '');
		} else {
			$temp = array(
				'id'        => 'wpPreview',
				'name'      => 'wpPreview',
				'type'      => 'submit',
				# MeanEditor: should be harmless in any case
				'class'     => 'wymupdate',
				'tabindex'  => $tabindex,
				'value'     => wfMsg('showpreview'),
				'accesskey' => wfMsg('accesskey-preview'),
				'title'     => wfMsg( 'tooltip-preview' ).' ['.wfMsg( 'accesskey-preview' ).']',
			);
			$buttons['preview'] = Xml::element('input', $temp, '');
			$buttons['live'] = '';
		}

		$temp = array(
			'id'        => 'wpDiff',
			'name'      => 'wpDiff',
			'type'      => 'submit',
			# MeanEditor: should be harmless in any case
			'class'     => 'wymupdate',
			'tabindex'  => ++$tabindex,
			'value'     => wfMsg('showdiff'),
			'accesskey' => wfMsg('accesskey-diff'),
			'title'     => wfMsg( 'tooltip-diff' ).' ['.wfMsg( 'accesskey-diff' ).']',
		);
		$buttons['diff'] = Xml::element('input', $temp, '');

		wfRunHooks( 'EditPageBeforeEditButtons', array( &$this, &$buttons, &$tabindex ) );
		return $buttons;
	}

}
