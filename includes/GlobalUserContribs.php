<?php

use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MediaWikiServices;
use Wikimedia\IPUtils;

class GlobalUserContribs extends ContextSource {
	/** @var User to fetch contributions for */
	protected $user;

	/** @var array|bool */
	protected $namespaces = false;

	/**
	 * @param User $user
	 * @param IContextSource $context
	 */
	public function __construct( User $user, IContextSource $context ) {
		$this->user = $user;
		$this->setContext( $context );
	}

	/**
	 * return a list of databases we should check on
	 * for the current user
	 *
	 * @return array
	 */
	protected function getWikisToQuery() {
		$wikis = $this->getWikiList();
		// Try to use the CA localnames table if possible
		if ( class_exists( 'CentralAuthUser' ) && !IPUtils::isIPAddress( $this->user->getName() ) ) {
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
	 *
	 * @param \Wikimedia\Rdbms\IDatabase $db
	 * @return stdClass|bool false if not blocked
	 */
	protected function getBlockInfo( $db ) {
		// I totally stole this from CentralAuth
		if ( !IPUtils::isValid( $this->user->getName() ) ) {
			$conds = [ 'ipb_address' => $this->user->getName() ];
		} else {
			$conds = [ 'ipb_address' => IPUtils::toHex( $this->user->getName() ) ];
		}

		$row = $db->selectRow( 'ipblocks',
			[ 'ipb_expiry', 'ipb_reason', 'ipb_deleted' ],
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
	 *
	 * @param string $wiki wikiid
	 * @return array|bool false if user doesn't exist or is hidden
	 */
	protected function loadLocalData( $wiki ) {
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lb = $lbFactory->getMainLB( $wiki );
		$db = $lb->getConnection( DB_REPLICA, [], $wiki );
		$data = [ 'revisions' => [], 'block' => [] ];

		$conds = [
			// @todo let users with rights see deleted stuff
			'rev_deleted' => 0,
		];

		$fields = [
			'rev_id', 'rev_comment', 'rev_timestamp', 'rev_minor_edit',
			'rev_len', 'rev_parent_id', 'rev_page',
			'page_title', 'page_namespace',
		];
		$join = [
			'page' => [ 'JOIN', 'rev_page=page_id' ]
		];

		if ( !IPUtils::isIPAddress( $this->user->getName() ) ) {
			$row = $db->selectRow(
				'user',
				[ 'user_id', 'user_editcount' ],
				[ 'user_name' => $this->user->getName() ],
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
					// hideuser, pretend it doesn't exist.
					return false;
				}
				return $data;
			}
			$conds['rev_user'] = $row->user_id;
		} else {
			$conds['rev_user_text'] = $this->user->getName();
		}
		$rows = $db->select(
			[ 'revision', 'page' ],
			$fields,
			$conds,
			__METHOD__,
			// @todo make limit configurable
			[ 'LIMIT' => 20, 'ORDER BY' => 'rev_timestamp DESC' ],
			$join
		);

		$data['revisions'] = $rows;
		$data['blocks'] = $this->getBlockInfo( $db );

		$lb->reuseConnection( $db );

		if ( $data['block'] && $data['block']->ipb_deleted !== 0 ) {
			// hideuser, pretend it doesn't exist.
			return false;
		}

		return $data;
	}

	/**
	 * Assumes whomever set up this farm was sane enough
	 * to use the same script path everywhere
	 *
	 * @param string $wiki
	 * @param string $type
	 * @return string|null
	 */
	protected function getForeignScript( $wiki, $type = 'index' ) {
		$wikiRef = WikiMap::getWiki( $wiki );
		if ( !$wikiRef ) {
			return null;
		}
		return $wikiRef->getCanonicalServer() . wfScript( $type );
	}

	/**
	 * Turns a revision into a HTML row
	 *
	 * @param string $wiki
	 * @param stdClass $row
	 * @return string HTML
	 */
	protected function formatRow( $wiki, $row ) {
		$index = $this->getForeignScript( $wiki );
		if ( !$index ) {
			return '';
		}

		$html = Html::openElement( 'li', [ 'class' => 'mw-guc-changes-item plainlinks' ] );
		$lang = $this->getLanguage();
		$sep = ' <span class="mw-changeslist-separator">. .</span> ';

		$ts = $lang->userTimeAndDate( $row->rev_timestamp, $this->getUser() );
		$url = wfAppendQuery( $index, [ 'oldid' => $row->rev_id ] );
		$html .= Linker::makeExternalLink( $url, $ts );
		$diff = wfAppendQuery( $index, [ 'diff' => $row->rev_id ] );
		$difftext = Linker::makeExternalLink( $diff, $this->msg( 'diff' )->escaped() );
		$hist = wfAppendQuery( $index, [ 'action' => 'history', 'curid' => $row->rev_page ] );
		$histtext = Linker::makeExternalLink( $hist, $this->msg( 'hist' )->escaped() );

		$html .= ' ';
		$html .= $this->msg( 'parentheses' )
			->rawParams( $difftext . $this->msg( 'pipe-separator' )->escaped() . $histtext )
			->escaped();
		// Divider
		$html .= $sep;

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
			wfAppendQuery( $index, [ 'curid' => $row->rev_page ] ),
			$normTitle
		);

		$html .= ' ';

		// @todo make links here...
		//$html .= Linker::formatComment( $row->rev_comment );
		if ( $row->rev_comment ) {
			$html .= '<span class="comment">'
				. $this->msg( 'parentheses' )
					->plaintextParams( $row->rev_comment )
					->escaped()
				. '</span>';
		}
		// $html .= htmlspecialchars( $row->rev_comment );

		$html .= Html::closeElement( 'li' );
		return $html;
	}

	/**
	 * @param string|bool $wiki
	 * @param array $data
	 * @return string
	 */
	protected function formatWiki( $wiki, $data ) {
		$wikiRef = WikiMap::getWiki( $wiki );
		if ( !$wikiRef ) {
			return '';
		}

		$html = Html::element( 'h2', [ 'class' => 'mw-guc-header' ], $wikiRef->getDisplayName() );
		$html .= Html::openElement( 'ul', [ 'class' => 'mw-guc-changes-list' ] );
		foreach ( $data['revisions'] as $row ) {
			$html .= $this->formatRow( $wiki, $row );
		}
		$html .= Html::closeElement( 'ul' );
		return $html;
	}

	/**
	 * @return string
	 */
	public function getHtml() {
		$html = '';
		foreach ( $this->getContribs() as $wiki => $data ) {
			$html .= $this->formatWiki( $wiki, $data );
		}

		$this->saveNSToCache();

		return $html;
	}

	/**
	 * @return array
	 */
	public function getContribs() {
		$wikis = $this->getWikisToQuery();
		$data = [];
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
	 * @param int $nsid
	 * @return string
	 */
	protected function getForeignNSName( $wiki, $nsid ) {
		global $wgConf;
		$cache = ObjectCache::getInstance( CACHE_ANYTHING );

		if ( $this->namespaces === false ) {
			$data = $cache->get( 'guc::namespaces' );
			if ( $data === false ) {
				$this->namespaces = [];
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
		$params = [
			'action' => 'query',
			'meta' => 'siteinfo',
			'siprop' => 'namespaces',
			'format' => 'json'
		];

		$api = $this->getForeignScript( $wiki, 'api' );
		if ( $api ) {
			$url = wfAppendQuery( $api, $params );
			$req = MediaWikiServices::getInstance()->getHttpRequestFactory()->create( $url );
			$req->execute();
			$json = $req->getContent();
			$decoded = FormatJson::decode( $json, true );
			// Store everything we've got.
			$map = array_column( $decoded['query']['namespaces'], '*' );
			$this->namespaces[$wiki] = array_merge( $this->namespaces[$wiki], $map );
		}
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
			ObjectCache::getInstance( CACHE_ANYTHING )->set( 'guc::namespaces', $this->namespaces );
		}
	}
}
