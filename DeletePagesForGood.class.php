<?php

class DeletePagesForGood {

	function __construct() {
		global $wgHooks;

		$wgHooks['SkinTemplateNavigation::Universal'][] = array(
			&$this,
			'AddSkinHook'
		);
	}

	function AddSkinHook( SkinTemplate &$sktemplate, array &$links ) {
		global $wgRequest, $wgTitle, $wgUser, $wgDeletePagesForGoodNamespaces;

		if ( !$wgUser->isAllowed( 'deleteperm' ) ) {

			return false;
		}

		$action = $wgRequest->getText( 'action' );

		# Special pages can not be deleted (special pages have no article id anyway).
		if ( $wgTitle->getArticleID() != 0
			&& isset( $wgDeletePagesForGoodNamespaces[$wgTitle->getNamespace()] )
			&& $wgDeletePagesForGoodNamespaces[$wgTitle->getNamespace()] == true
			&& $wgTitle->getNamespace() != NS_SPECIAL
		) {
			$links['actions']['ask_delete_page_permanently'] = array(
				'class' => ( $action == 'ask_delete_page_permanently' ) ? 'selected' : false,
				'text' => wfMessage( 'deletepagesforgood-delete_permanently' )->text(),
				'href' => $wgTitle->getLocalUrl( 'action=ask_delete_page_permanently' )
			);
		}

		return true;
	}

	function deletePermanently( $title ) {
		global $wgOut;

		$ns = $title->getNamespace();
		$t = $title->getDBkey();
		$id = $title->getArticleID();
		$cats = $title->getParentCategories();

		$dbw = wfGetDB( DB_MASTER );

		$dbw->begin();

		/*
		 * First delete entries, which are in direct relation with the page:
		 */

		# delete redirect...
		$dbw->delete( 'redirect', array( 'rd_from' => $id ), __METHOD__ );

		# delete external link...
		$dbw->delete( 'externallinks', array( 'el_from' => $id ), __METHOD__ );

		# delete language link...
		$dbw->delete( 'langlinks', array( 'll_from' => $id ), __METHOD__ );

		# delete search index...
		$dbw->delete( 'searchindex', array( 'si_page' => $id ), __METHOD__ );

		# Delete restrictions for the page
		$dbw->delete( 'page_restrictions', array( 'pr_page' => $id ), __METHOD__ );

		# Delete page Links
		$dbw->delete( 'pagelinks', array( 'pl_from' => $id ), __METHOD__ );

		# delete category links
		$dbw->delete( 'categorylinks', array( 'cl_from' => $id ), __METHOD__ );

		# delete template links
		$dbw->delete( 'templatelinks', array( 'tl_from' => $id ), __METHOD__ );

		# read text entries for all revisions and delete them.
		$res = $dbw->select( 'revision', 'rev_text_id', "rev_page=$id" );

		while ( $row = $dbw->fetchObject( $res ) ) {
			$value = $row->rev_text_id;
			$dbw->delete( 'text', array( 'old_id' => $value ), __METHOD__ );
		}

		# In the table 'revision' : Delete all the revision of the page where 'rev_page' = $id
		$dbw->delete( 'revision', array( 'rev_page' => $id ), __METHOD__ );

		# delete image links
		$dbw->delete( 'imagelinks', array( 'il_from' => $id ), __METHOD__ );

		/*
		 * then delete entries which are not in direct relation with the page:
		 */

		# Clean up recentchanges entries...
		$dbw->delete( 'recentchanges', array(
			'rc_namespace' => $ns,
			'rc_title' => $t
		), __METHOD__ );

		# read text entries for all archived pages and delete them.
		$res = $dbw->select( 'archive', 'ar_text_id', array(
			'ar_namespace' => $ns,
			'ar_title' => $t
		) );

		while ( $row = $dbw->fetchObject( $res ) ) {
			$value = $row->ar_text_id;
			$dbw->delete( 'text', array( 'old_id' => $value ), __METHOD__ );
		}

		# Clean archive entries...
		$dbw->delete( 'archive', array(
			'ar_namespace' => $ns,
			'ar_title' => $t
		), __METHOD__ );

		# Clean up log entries...
		$dbw->delete( 'logging', array(
			'log_namespace' => $ns,
			'log_title' => $t
		), __METHOD__ );

		# Clean up watchlist...
		$dbw->delete( 'watchlist', array(
			'wl_namespace' => $ns,
			'wl_title' => $t
		), __METHOD__ );

		# In the table 'page' : Delete the page entry
		$dbw->delete( 'page', array( 'page_id' => $id ), __METHOD__ );

		/*
		 * If the article belongs to a category, update category counts
		 */
		if ( !empty( $cats ) ) {
			foreach ( $cats as $parentcat => $currentarticle ) {
				$catname = preg_split( ':', $parentcat, 2 );
				$cat = Category::newFromName( $catname[1] );
				$cat->refreshCounts();
			}
		}

		/*
		 * If an image is beeing deleted, some extra work needs to be done
		 */
		if ( $ns == NS_IMAGE ) {

			$file = wfFindFile( $t );

			if ( $file ) {
				# Get all filenames of old versions:
				$fields = OldLocalFile::selectFields();
				$res = $dbw->select( 'oldimage', $fields, array( 'oi_name' => $t ) );

				while ( $row = $dbw->fetchObject( $res ) ) {
					$oldLocalFile = OldLocalFile::newFromRow( $row, $file->repo );
					$path = $oldLocalFile->getArchivePath() . '/' . $oldLocalFile->getArchiveName();

					try {
						unlink( $path );
					}
					catch ( Exception $e ) {
						$wgOut->addHTML( $e->getMessage() );
					}
				}

				$path = $file->getPath();

				try {
					$file->purgeThumbnails();
					unlink( $path );
				} catch ( Exception $e ) {
					$wgOut->addHTML( $e->getMessage() );
				}
			}

			# clean the filearchive for the given filename:
			$dbw->delete( 'filearchive', array( 'fa_name' => $t ), __METHOD__ );

			# Delete old db entries of the image:
			$dbw->delete( 'oldimage', array( 'oi_name' => $t ), __METHOD__ );

			# Delete archive entries of the image:
			$dbw->delete( 'filearchive', array( 'fa_name' => $t ), __METHOD__ );

			# Delete image entry:
			$dbw->delete( 'image', array( 'img_name' => $t ), __METHOD__ );

			$dbw->commit();

			$linkCache = LinkCache::singleton();
			$linkCache->clear();
		}
	}
}

class ActionAskDeletePagePermanently extends FormAction {

	public function getName() {
		return 'ask_delete_page_permanently';
	}

	public function requiresUnblock() {
		return false;
	}

	public function getDescription() {
		return '';
	}

	public function onSubmit( $data ) {
		$this->deletepagesforgood($action, $wgArticle);

		return true;
	}
	protected $mPage;
	public function deletePermanently( $title ) {
		global $wgOut;

		$ns = $title->getNamespace();
		$t = $title->getDBkey();
		$id = $title->getArticleID();
		$cats = $title->getParentCategories();

		$dbw = wfGetDB( DB_MASTER );

		$dbw->begin();

		/*
		 * First delete entries, which are in direct relation with the page:
		 */

		# delete redirect...
		$dbw->delete( 'redirect', array( 'rd_from' => $id ), __METHOD__ );

		# delete external link...
		$dbw->delete( 'externallinks', array( 'el_from' => $id ), __METHOD__ );

		# delete language link...
		$dbw->delete( 'langlinks', array( 'll_from' => $id ), __METHOD__ );

		# delete search index...
		$dbw->delete( 'searchindex', array( 'si_page' => $id ), __METHOD__ );

		# Delete restrictions for the page
		$dbw->delete( 'page_restrictions', array( 'pr_page' => $id ), __METHOD__ );

		# Delete page Links
		$dbw->delete( 'pagelinks', array( 'pl_from' => $id ), __METHOD__ );

		# delete category links
		$dbw->delete( 'categorylinks', array( 'cl_from' => $id ), __METHOD__ );

		# delete template links
		$dbw->delete( 'templatelinks', array( 'tl_from' => $id ), __METHOD__ );

		# read text entries for all revisions and delete them.
		$res = $dbw->select( 'revision', 'rev_text_id', "rev_page=$id" );

		while ( $row = $dbw->fetchObject( $res ) ) {
			$value = $row->rev_text_id;
			$dbw->delete( 'text', array( 'old_id' => $value ), __METHOD__ );
		}

		# In the table 'revision' : Delete all the revision of the page where 'rev_page' = $id
		$dbw->delete( 'revision', array( 'rev_page' => $id ), __METHOD__ );

		# delete image links
		$dbw->delete( 'imagelinks', array( 'il_from' => $id ), __METHOD__ );

		/*
		 * then delete entries which are not in direct relation with the page:
		 */

		# Clean up recentchanges entries...
		$dbw->delete( 'recentchanges', array(
			'rc_namespace' => $ns,
			'rc_title' => $t
		), __METHOD__ );

		# read text entries for all archived pages and delete them.
		$res = $dbw->select( 'archive', 'ar_text_id', array(
			'ar_namespace' => $ns,
			'ar_title' => $t
		) );

		while ( $row = $dbw->fetchObject( $res ) ) {
			$value = $row->ar_text_id;
			$dbw->delete( 'text', array( 'old_id' => $value ), __METHOD__ );
		}

		# Clean archive entries...
		$dbw->delete( 'archive', array(
			'ar_namespace' => $ns,
			'ar_title' => $t
		), __METHOD__ );

		# Clean up log entries...
		$dbw->delete( 'logging', array(
			'log_namespace' => $ns,
			'log_title' => $t
		), __METHOD__ );

		# Clean up watchlist...
		$dbw->delete( 'watchlist', array(
			'wl_namespace' => $ns,
			'wl_title' => $t
		), __METHOD__ );

		# In the table 'page' : Delete the page entry
		$dbw->delete( 'page', array( 'page_id' => $id ), __METHOD__ );

		/*
		 * If the article belongs to a category, update category counts
		 */
		if ( !empty( $cats ) ) {
			foreach ( $cats as $parentcat => $currentarticle ) {
				$catname = preg_split( ':', $parentcat, 2 );
				$cat = Category::newFromName( $catname[1] );
				$cat->refreshCounts();
			}
		}

		/*
		 * If an image is beeing deleted, some extra work needs to be done
		 */
		if ( $ns == NS_IMAGE ) {

			$file = wfFindFile( $t );

			if ( $file ) {
				# Get all filenames of old versions:
				$fields = OldLocalFile::selectFields();
				$res = $dbw->select( 'oldimage', $fields, array( 'oi_name' => $t ) );

				while ( $row = $dbw->fetchObject( $res ) ) {
					$oldLocalFile = OldLocalFile::newFromRow( $row, $file->repo );
					$path = $oldLocalFile->getArchivePath() . '/' . $oldLocalFile->getArchiveName();

					try {
						unlink( $path );
					}
					catch ( Exception $e ) {
						$wgOut->addHTML( $e->getMessage() );
					}
				}

				$path = $file->getPath();

				try {
					$file->purgeThumbnails();
					unlink( $path );
				} catch ( Exception $e ) {
					$wgOut->addHTML( $e->getMessage() );
				}
			}

			# clean the filearchive for the given filename:
			$dbw->delete( 'filearchive', array( 'fa_name' => $t ), __METHOD__ );

			# Delete old db entries of the image:
			$dbw->delete( 'oldimage', array( 'oi_name' => $t ), __METHOD__ );

			# Delete archive entries of the image:
			$dbw->delete( 'filearchive', array( 'fa_name' => $t ), __METHOD__ );

			# Delete image entry:
			$dbw->delete( 'image', array( 'img_name' => $t ), __METHOD__ );

			$dbw->commit();

			$linkCache = LinkCache::singleton();
			$linkCache->clear();
		}
	}
	/**
	 * purge is slightly weird because it can be either formed or formless depending
	 * on user permissions
	 */
	public function show() {
		$this->setHeaders();

		// This will throw exceptions if there's a problem
		$this->checkCanExecute( $this->getUser() );

		$user = $this->getUser();
		
		$this->deletepagesforgood($title);
		$this->confirmDelete( $title);
	}

	public function deletepagesforgood($title) {
		global $wgOut, $wgDeletePagesForGoodNamespaces;
		 

	



		global $wgOut;

		$ns = $title->getNamespace();
		$t = $title->getDBkey();
		$id = $title->getArticleID();
		$cats = $title->getParentCategories();

		$dbw = wfGetDB( DB_MASTER );

		$dbw->begin();

		/*
		 * First delete entries, which are in direct relation with the page:
		 */

		# delete redirect...
		$dbw->delete( 'redirect', array( 'rd_from' => $id ), __METHOD__ );

		# delete external link...
		$dbw->delete( 'externallinks', array( 'el_from' => $id ), __METHOD__ );

		# delete language link...
		$dbw->delete( 'langlinks', array( 'll_from' => $id ), __METHOD__ );

		# delete search index...
		$dbw->delete( 'searchindex', array( 'si_page' => $id ), __METHOD__ );

		# Delete restrictions for the page
		$dbw->delete( 'page_restrictions', array( 'pr_page' => $id ), __METHOD__ );

		# Delete page Links
		$dbw->delete( 'pagelinks', array( 'pl_from' => $id ), __METHOD__ );

		# delete category links
		$dbw->delete( 'categorylinks', array( 'cl_from' => $id ), __METHOD__ );

		# delete template links
		$dbw->delete( 'templatelinks', array( 'tl_from' => $id ), __METHOD__ );

		# read text entries for all revisions and delete them.
		$res = $dbw->select( 'revision', 'rev_text_id', "rev_page=$id" );

		while ( $row = $dbw->fetchObject( $res ) ) {
			$value = $row->rev_text_id;
			$dbw->delete( 'text', array( 'old_id' => $value ), __METHOD__ );
		}

		# In the table 'revision' : Delete all the revision of the page where 'rev_page' = $id
		$dbw->delete( 'revision', array( 'rev_page' => $id ), __METHOD__ );

		# delete image links
		$dbw->delete( 'imagelinks', array( 'il_from' => $id ), __METHOD__ );

		/*
		 * then delete entries which are not in direct relation with the page:
		 */

		# Clean up recentchanges entries...
		$dbw->delete( 'recentchanges', array(
			'rc_namespace' => $ns,
			'rc_title' => $t
		), __METHOD__ );

		# read text entries for all archived pages and delete them.
		$res = $dbw->select( 'archive', 'ar_text_id', array(
			'ar_namespace' => $ns,
			'ar_title' => $t
		) );

		while ( $row = $dbw->fetchObject( $res ) ) {
			$value = $row->ar_text_id;
			$dbw->delete( 'text', array( 'old_id' => $value ), __METHOD__ );
		}

		# Clean archive entries...
		$dbw->delete( 'archive', array(
			'ar_namespace' => $ns,
			'ar_title' => $t
		), __METHOD__ );

		# Clean up log entries...
		$dbw->delete( 'logging', array(
			'log_namespace' => $ns,
			'log_title' => $t
		), __METHOD__ );

		# Clean up watchlist...
		$dbw->delete( 'watchlist', array(
			'wl_namespace' => $ns,
			'wl_title' => $t
		), __METHOD__ );

		# In the table 'page' : Delete the page entry
		$dbw->delete( 'page', array( 'page_id' => $id ), __METHOD__ );

		/*
		 * If the article belongs to a category, update category counts
		 */
		if ( !empty( $cats ) ) {
			foreach ( $cats as $parentcat => $currentarticle ) {
				$catname = preg_split( ':', $parentcat, 2 );
				$cat = Category::newFromName( $catname[1] );
				$cat->refreshCounts();
			}
		}

		/*
		 * If an image is beeing deleted, some extra work needs to be done
		 */
		if ( $ns == NS_IMAGE ) {

			$file = wfFindFile( $t );

			if ( $file ) {
				# Get all filenames of old versions:
				$fields = OldLocalFile::selectFields();
				$res = $dbw->select( 'oldimage', $fields, array( 'oi_name' => $t ) );

				while ( $row = $dbw->fetchObject( $res ) ) {
					$oldLocalFile = OldLocalFile::newFromRow( $row, $file->repo );
					$path = $oldLocalFile->getArchivePath() . '/' . $oldLocalFile->getArchiveName();

					try {
						unlink( $path );
					}
					catch ( Exception $e ) {
						$wgOut->addHTML( $e->getMessage() );
					}
				}

				$path = $file->getPath();

				try {
					$file->purgeThumbnails();
					unlink( $path );
				} catch ( Exception $e ) {
					$wgOut->addHTML( $e->getMessage() );
				}
			}

			# clean the filearchive for the given filename:
			$dbw->delete( 'filearchive', array( 'fa_name' => $t ), __METHOD__ );

			# Delete old db entries of the image:
			$dbw->delete( 'oldimage', array( 'oi_name' => $t ), __METHOD__ );

			# Delete archive entries of the image:
			$dbw->delete( 'filearchive', array( 'fa_name' => $t ), __METHOD__ );

			# Delete image entry:
			$dbw->delete( 'image', array( 'img_name' => $t ), __METHOD__ );

			$dbw->commit();

			$linkCache = LinkCache::singleton();
			$linkCache->clear();
			}

			if ( $t == '' || $id == 0 || $wgDeletePagesForGoodNamespaces[$ns] != true
				|| $ns == NS_SPECIAL
			) {
				$wgOut->addHTML( wfMessage( 'deletepagesforgood-del_impossible' )->escaped() );
				return false;
			}

			$wgOut->addHTML( wfMessage( 'deletepagesforgood-del_done' )->escaped() );
			return false;
		
	}
	public function confirmDelete( $reason ) {
		wfDebug( "Article::confirmDelete\n" );

		$title = $this->getTitle();
		$ctx = $this->getContext();
		$outputPage = $ctx->getOutput();
		$useMediaWikiUIEverywhere = $ctx->getConfig()->get( 'UseMediaWikiUIEverywhere' );
		$outputPage->setPageTitle( wfMessage( 'delete-confirm', $title->getPrefixedText() ) );
		$outputPage->addBacklinkSubtitle( $title );
		$outputPage->setRobotPolicy( 'noindex,nofollow' );
		$backlinkCache = $title->getBacklinkCache();
		if ( $backlinkCache->hasLinks( 'pagelinks' ) || $backlinkCache->hasLinks( 'templatelinks' ) ) {
			$outputPage->wrapWikiMsg( "<div class='mw-warning plainlinks'>\n$1\n</div>\n",
				'deleting-backlinks-warning' );
		}
		$outputPage->addWikiMsg( 'confirmdeletetext' );

		Hooks::run( 'ArticleConfirmDelete', array( $this, $outputPage, &$reason ) );

		$user = $this->getContext()->getUser();

		if ( $user->isAllowed( 'suppressrevision' ) ) {
			$suppress = Html::openElement( 'div', array( 'id' => 'wpDeleteSuppressRow' ) ) .
				Xml::checkLabel( wfMessage( 'revdelete-suppress' )->text(),
					'wpSuppress', 'wpSuppress', false, array( 'tabindex' => '4' ) ) .
				Html::closeElement( 'div' );
		} else {
			$suppress = '';
		}
		$checkWatch = $user->getBoolOption( 'watchdeletion' ) || $user->isWatched( $title );

		$form = Html::openElement( 'form', array( 'method' => 'post',
			'action' => $title->getLocalURL( 'action=ask_delete_page_permanently' ), 'id' => 'ask_delete_page_permanently' ) ) .
			Html::openElement( 'fieldset', array( 'id' => 'mw-delete-table' ) ) .
			Html::element( 'legend', null, wfMessage( 'delete-legend' )->text() ) .
			Html::openElement( 'div', array( 'id' => 'mw-deleteconfirm-table' ) ) .
			Html::openElement( 'div', array( 'id' => 'wpDeleteReasonListRow' ) ) .
			Html::label( wfMessage( 'deletecomment' )->text(), 'wpDeleteReasonList' ) .
			'&nbsp;' .

			Html::closeElement( 'div' ) .
			Html::openElement( 'div', array( 'id' => 'wpDeleteReasonRow' ) ) .
			Html::label( wfMessage( 'deleteotherreason' )->text(), 'wpReason' ) .
			'&nbsp;' .
			Html::input( 'wpReason', $reason, 'text', array(
				'size' => '60',
				'maxlength' => '255',
				'tabindex' => '2',
				'id' => 'wpReason',
				'class' => 'mw-ui-input-inline',
				'autofocus'
			) ) .
			Html::closeElement( 'div' );

		# Disallow watching if user is not logged in
		if ( $user->isLoggedIn() ) {
			$form .=
					Xml::checkLabel( wfMessage( 'watchthis' )->text(),
						'wpWatch', 'wpWatch', $checkWatch, array( 'tabindex' => '3' ) );
		}

		$form .=
				Html::openElement( 'div' ) .
				$suppress .
					Xml::submitButton( wfMessage( 'deletepage' )->text(),
						array(
							'name' => 'wpConfirmB',
							'id' => 'wpConfirmB',
							'tabindex' => '5',
							'class' => $useMediaWikiUIEverywhere ? 'mw-ui-button mw-ui-destructive' : '',
						)
					) .
				Html::closeElement( 'div' ) .
			Html::closeElement( 'div' ) .
			Xml::closeElement( 'fieldset' ) .
			Html::hidden(
				'wpEditToken',
				$user->getEditToken( array( 'delete', $title->getPrefixedText() ) )
			) .
			Xml::closeElement( 'form' );

		$outputPage->addHTML( $form );
	}
	protected function alterForm( HTMLForm $form ) {
		$form->setSubmitTextMsg( 'deletepagesforgood-yes' );
	}

	public static function AddActionAskDeletePagePermanently( $action, $wgArticle ) {
		global $wgOut, $wgUser, $wgDeletePagesForGoodNamespaces;


		# Print a form to approve deletion
		if ( $action == 'ask_delete_page_permanently' ) {

			$action = $wgArticle->getTitle()->getLocalUrl( 'action=delete_page_permanently' );
			$wgOut->addHTML( "<form id='ask_delete_page_permanently' method='post' action=\"$action\">
				<table>
						<tr>
							<td>" . wfMessage( 'deletepagesforgood-ask_deletion' )->text() . "</td>
						</tr>
						<tr>
							<td><input type='submit' name='submit' value=\"" .
								wfMessage( 'deletepagesforgood-yes' )->text() . "\" />
							</td>
						</tr>
				</table></form>"
			);
			return false;
		}

		return true;
	}

	public function getRestriction() {
		return 'deleteperm';
		// global $wgOut, $wgUser;

		// if ( !$wgUser->isAllowed( 'deleteperm' ) ) {
			// $wgOut->permissionRequired( 'deleteperm' );

			// return false;
		// }
	}


	protected function checkCanExecute( User $user ) {
		// Must be logged in
		if ( $user->isAnon() ) {
			throw new UserNotLoggedIn( 'deleteperm' );
		}

		parent::checkCanExecute( $user );
	}
	public function onSuccess() {
		$this->getOutput()->redirect( $this->getTitle()->getFullURL( $this->redirectParams ) );
	}
}

class ActionDeletePagePermanently extends FormAction {
	public function getName() {
		return 'delete_page_permanently';
	}
	public function onSuccess() {
		$this->getOutput()->redirect( $this->getTitle()->getFullURL( $this->redirectParams ) );
	}
	public function onSubmit( $data ) {
		return $this->page->doPurge();
	}
	function AddActionDeletePagePermanently( $action, $wgArticle ) {
		global $wgOut, $wgUser, $wgDeletePagesForGoodNamespaces;

		if ( $action == 'delete_page_permanently' ) {
			# Perform actual deletion
			$ns = $wgArticle->mTitle->getNamespace();
			$t = $wgArticle->mTitle->getDBkey();
			$id = $wgArticle->mTitle->getArticleID();

			if ( $t == '' || $id == 0 || $wgDeletePagesForGoodNamespaces[$ns] != true
				|| $ns == NS_SPECIAL
			) {
				$wgOut->addHTML( wfMessage( 'deletepagesforgood-del_impossible' )->escaped() );
				return false;
			}

			$this->deletePermanently( $wgArticle->mTitle );
			$wgOut->addHTML( wfMessage( 'deletepagesforgood-del_done' )->escaped() );
			return false;
		}

		return true;
	}

	protected function alterForm( HTMLForm $form ) {
		$form->setSubmitTextMsg( 'confirm_purge_button' );
	}


	public function getRestriction() {
		return 'deleteperm';
		// global $wgOut, $wgUser;

		// if ( !$wgUser->isAllowed( 'deleteperm' ) ) {
			// $wgOut->permissionRequired( 'deleteperm' );

			// return false;
		// }
	}


	protected function checkCanExecute( User $user ) {
		// Must be logged in
		if ( $user->isAnon() ) {
			throw new UserNotLoggedIn( 'deleteperm' );
		}

		parent::checkCanExecute( $user );
	}
}
