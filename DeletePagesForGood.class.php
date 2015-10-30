<?php

class ActionDeletePagePermanently extends FormAction {

	public static function AddSkinHook( SkinTemplate &$sktemplate, array &$links ) {
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
			$links['actions']['delete_page_permanently'] = array(
				'class' => ( $action == 'delete_page_permanently' ) ? 'selected' : false,
				'text' => wfMessage( 'deletepagesforgood-delete_permanently' )->text(),
				'href' => $wgTitle->getLocalUrl( 'action=delete_page_permanently' )
			);
		}

		return true;
	}

	public function getName() {
		return 'delete_page_permanently';
	}

	public function getDescription() {
		return '';
	}

	public function onSubmit( $data ) {

		$this->deletePermanently( $title );

		return true;
	}

	public function deletePermanently( $title ) {
		global $wgOut;

		$title = $this->getTitle();

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

	protected function alterForm( HTMLForm $form ) {
		global $wgOut;

		$title = $this->getTitle();
		$wgOut->addBacklinkSubtitle( $title );
		$wgOut->setPageTitle(
			wfMessage( 'deletepagesforgood-deletepagetitle', $title->getPrefixedText() )
		);
		$wgOut->setRobotPolicy( 'noindex,nofollow' );
		$wgOut->addWikiMsg( 'confirmdeletetext' );

		$wgOut->addHTML(
			"<form id='delete_page_permanently' method='post'>
				<table>
						<tr>
							<td>" . wfMessage( 'deletepagesforgood-ask_deletion' )->text() . "</td>
						</tr>
						<tr>

						</tr>
				</table>
			</form>"
		);

		$form->setSubmitTextMsg( 'deletepagesforgood-yes' );
	}

	public function getRestriction() {
		return 'deleteperm';
	}

	public function onSuccess() {
		global $wgOut, $wgDeletePagesForGoodNamespaces;

		$wgOut->setRobotPolicy( 'noindex,nofollow' );

		$title = $this->getTitle();
		$ns = $title->getNamespace();
		$t = $title->getDBkey();
		$id = $title->getArticleID();

		if ( $t == '' || $id == 0 || $wgDeletePagesForGoodNamespaces[$ns] != true
			|| $ns == NS_SPECIAL
		) {
			$wgOut->addHTML( wfMessage( 'deletepagesforgood-del_impossible' )->escaped() );
			return false;
		}
		$wgOut->addHTML( wfMessage( 'deletepagesforgood-del_done' )->escaped() );
		return false;
	}
}
