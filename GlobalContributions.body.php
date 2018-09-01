<?php

use MediaWiki\MediaWikiServices;

class GlobalUserContribs extends ContextSource {
	/** @var User $user to fetch contributions for */
	protected $user;
	/** @var array|bool $namespaces */
	protected $namespaces = false;

	public function __construct( User $user, IContextSource $context ) {
		$this->user = $user;
		$this->setContext( $context );
	}

	/**
	 * return a list of databases we should check on
	 * for the current user
	 * @return array
	 */
	protected function getWikisToQuery() {
		$wikis = $this->getWikiList();
		// Try to use the CA localnames table if possible
		if ( class_exists( 'CentralAuthUser' ) && !IP::isIPAddress( $this->user->getName() ) ) {
			$caUser = CentralAuthUser::getInstance( $this->user );
			return array_intersect(
				array_merge( $caUser->listAttached(), $caUser->listUnattached() ),
				$wikis
			);
		}

		return $wikis;
	}

	/**
	 * @return array
	 */
	protected function getWikiList() {
		global $wgGUCWikis, $wgLocalDatabases;
		if ( empty( $wgGUCWikis ) ) {
			$wgGUCWikis = $wgLocalDatabases;
		}

		return $wgGUCWikis;
	}

	/**
	 * Get's a user's block info
	 * @param \Wikimedia\Rdbms\IDatabase $db
	 * @return stdClass|bool false if not blocked
	 */
	protected function getBlockInfo( $db ) {
		// I totally stole this from CentralAuth
		if ( !IP::isValid( $this->user->getName() ) ) {
			$conds = array( 'ipb_address' => $this->user->getName() );
		} else {
			$conds = array( 'ipb_address' => IP::toHex( $this->user->getName() ) );
		}

		$row = $db->selectRow( 'ipblocks',
			array( 'ipb_expiry', 'ipb_reason', 'ipb_deleted' ),
			$conds,
			__METHOD__ );
		if ( $row !== false
			&& $this->getLanguage()->formatExpiry( $row->ipb_expiry, TS_MW ) > wfTimestampNow()
		) {
			return $row;
		}

		return false;
	}

	/**
	 * Loads revisions for the specified wiki
	 * @param string $wiki wikiid
	 * @return array|bool false if user doesn't exist or is hidden
	 */
	protected function loadLocalData( $wiki ) {
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lb = $lbFactory->getMainLB( $wiki );
		$db = $lb->getConnection( DB_SLAVE, array(), $wiki );
		$data = array( 'revisions' => array(), 'block' => array() );

		$conds = array(
			'rev_deleted' => 0, // @todo let users with rights see deleted stuff
		);

		$fields = array(
			'rev_id', 'rev_comment', 'rev_timestamp', 'rev_minor_edit',
			'rev_len', 'rev_parent_id', 'rev_page',
			'page_title', 'page_namespace',
		);
		$join = array(
			'page' => array( 'JOIN', 'rev_page=page_id' )
		);

		if ( !IP::isIPAddress( $this->user->getName() ) ) {
			$row = $db->selectRow(
				'user',
				array( 'user_id', 'user_editcount' ),
				array( 'user_name' => $this->user->getName() ),
				__METHOD__
			);
			if ( $row === false ) {
				// This shouldn't be possible with shared user tables or CA
				// but...be safe.
				$lb->reuseConnection( $db );
				return false;
			}
			// This won't work for shared user tables but, if the user
			// has no edits, don't make the extra query and return early.
			if ( $row->user_editcount === 0 ) {
				$data['block'] = $this->getBlockInfo( $db );
				$lb->reuseConnection( $db );
				if ( $data['block'] && $data['block']->ipb_deleted !== 0 ) {
					return false; // hideuser, pretend it doesn't exist.
				}
				return $data;
			}
			$conds['rev_user'] = $row->user_id;
		} else {
			$conds['rev_user_text'] = $this->user->getName();
		}
		$rows = $db->select(
			array( 'revision', 'page' ),
			$fields,
			$conds,
			__METHOD__,
			array( 'LIMIT' => 20, 'ORDER BY' => 'rev_timestamp DESC' ), // @todo make limit configurable
			$join
		);

		$data['revisions'] = $rows;
		$data['blocks'] = $this->getBlockInfo( $db );

		$lb->reuseConnection( $db );

		if ( $data['block'] && $data['block']->ipb_deleted !== 0 ) {
			return false; // hideuser, pretend it doesn't exist.
		}

		return $data;

	}

	/**
	 * Assumes whomever set up this farm was sane enough
	 * to use the same script path everywhere
	 * @param $wiki
	 * @param string $type
	 * @return string
	 */
	protected function getForeignScript( $wiki, $type = 'index' ) {
		return WikiMap::getWiki( $wiki )->getCanonicalServer() . wfScript( $type );
	}

	/**
	 * Turns a revision into a HTML row
	 * @param string $wiki
	 * @param stdClass $row
	 * @return string HTML
	 */
	protected function formatRow( $wiki, $row ) {
		$html = Html::openElement( 'li', array( 'class' => 'mw-guc-changes-item plainlinks' ) );
		$lang = $this->getLanguage();
		$index = $this->getForeignScript( $wiki );
		$sep = ' <span class="mw-changeslist-separator">. .</span> ';


		$ts = $lang->userTimeAndDate( $row->rev_timestamp, $this->getUser() );
		$url = wfAppendQuery( $index, array( 'oldid' => $row->rev_id ) );
		$html .= Linker::makeExternalLink( $url, $ts );
		$diff = wfAppendQuery( $index, array( 'diff' => $row->rev_id ) );
		$difftext = Linker::makeExternalLink( $diff, $this->msg( 'diff')->escaped() );
		$hist = wfAppendQuery( $index, array( 'action' => 'history', 'curid' => $row->rev_page ) );
		$histtext = Linker::makeExternalLink( $hist, $this->msg( 'hist')->escaped() );

		$html .= ' ';
		$html .= $this->msg( 'parentheses' )
			->rawParams( $difftext . $this->msg( 'pipe-separator' )->escaped() . $histtext )
			->escaped();
		$html .= $sep; // Divider

		// @todo We are missing diff size here.

		if ( $row->rev_parent_id === '0' ) {
			$html .= ChangesList::flag( 'newpage' );
		}

		if ( $row->rev_minor_edit !== '0' ) {
			$html .= ChangesList::flag( 'minor' );
		}

		$html .= ' ';

		// Not a fan of this...but meh.
		$normTitle = str_replace( '_', ' ', $row->page_title );
		$nsName = $this->getForeignNSName( $wiki, $row->page_namespace );
		if ( $nsName ) {
			$normTitle = $nsName . ':' . $normTitle;
		}
		$html .= Linker::makeExternalLink(
			// Because our name might not be exact, link using page_id
			wfAppendQuery( $index, array( 'curid' => $row->rev_page) ),
			$normTitle
		);

		$html .= ' ';

		// @todo make links here...
		//$html .= Linker::formatComment( $row->rev_comment );
		if ( $row->rev_comment ) {
			$html .= '<span class="comment">'
				. $this->msg( 'parentheses' )
					->rawParams( htmlspecialchars( $row->rev_comment ) )
					->escaped()
				. '</span>';
		}
		//$html .= htmlspecialchars( $row->rev_comment );

		$html .= Html::closeElement( 'li' );
		return $html;
	}

	protected function formatWiki( $wiki, $data ) {
		$hostname = htmlspecialchars( WikiMap::getWiki( $wiki )->getDisplayName() );
		$html = "<h2 class=\"mw-guc-header\">$hostname</h2>";
		$html .= Html::openElement( 'ul', array( 'class' => 'mw-guc-changes-list' ) );
		foreach ( $data['revisions'] as $row ) {
			$html .= $this->formatRow( $wiki, $row );
		}
		$html .= Html::closeElement( 'ul' );
		return $html;
	}

	public function getHtml() {
		$html = '';
		foreach ( $this->getContribs() as $wiki => $data ) {
			$html .= $this->formatWiki( $wiki, $data );
		}

		$this->saveNSToCache();

		return $html;
	}

	public function getContribs() {
		$wikis = $this->getWikisToQuery();
		$data = array();
		foreach ( $wikis as $wiki ) {
			$localData = $this->loadLocalData( $wiki );
			if ( $localData !== false ) {
				$data[$wiki] = $localData;
			}
		}

		return $data;
	}

	/**
	 * @param string $wiki
	 * @param $nsid
	 * @return string
	 */
	protected function getForeignNSName( $wiki, $nsid ) {
		global $wgConf;
		$cache = wfGetCache( CACHE_ANYTHING );

		if ( $this->namespaces === false ) {
			$data = $cache->get( 'guc::namespaces' );
			if ( $data === false ) {
				$this->namespaces = array();
			} else {
				$this->namespaces = $data;
			}
		}

		if ( isset( $this->namespaces[$wiki][$nsid] ) ) {
			return $this->namespaces[$wiki][$nsid];
		}
		if ( $nsid < 16 || $nsid >= 200 ) {
			// Core or extension namespace.
			// Some extensions are bad and use 1XX, sucks for them.
			$name = $this->getLanguage()->getNsText( $nsid );
			if ( $name !== false ) {
				$this->namespaces[$wiki][$nsid] = $name;
				return $name;
			}
		}

		// Lets try $wgConf now...
		$extra = $wgConf->get( 'wgExtraNamespaces', $wiki );
		if ( isset( $extra[$nsid] ) ) {
			// Remove any underscores
			$name = str_replace( '_', ' ', $extra[$nsid] );
			$this->namespaces[$wiki][$nsid] = $name;
			return $name;
		}

		// Blegh. At this point, we should just make an API request.
		$params = array(
			'action' => 'query',
			'meta' => 'siteinfo',
			'siprop' => 'namespaces',
			'format' => 'json'
		);

		$api = $this->getForeignScript( $wiki, 'api' );
		$url = wfAppendQuery( $api, $params );
		$req = MWHttpRequest::factory( $url );
		$req->execute();
		$json = $req->getContent();
		$decoded = FormatJson::decode( $json, true );
		// Store everything we've got.
		$map = array_map( function( $val ) {
			return $val['*'];
		}, $decoded['query']['namespaces'] );
		$this->namespaces[$wiki] = array_merge( $this->namespaces[$wiki], $map );
		if ( isset( $this->namespaces[$wiki][$nsid] ) ) {
			return $this->namespaces[$wiki][$nsid];
		} else {
			// Ok, wtf. Just return the numerical id as a string.
			$this->namespaces[$wiki][$nsid] = (string)$nsid;
			return (string)$nsid;
		}
	}

	/**
	 * Saves the namespaces in memcached
	 * Run it after calling getForeignNSName
	 * a bunch of times.
	 */
	protected function saveNSToCache() {
		if ( $this->namespaces !== false ) {
			wfGetCache( CACHE_ANYTHING )->set( 'guc::namespaces', $this->namespaces );
		}
	}
}
