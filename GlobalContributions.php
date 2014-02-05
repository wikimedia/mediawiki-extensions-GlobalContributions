<?php
/*
 * Global user contributions extension
 * Adds Special:GlobalContributions for viewing a user
 * or IP address's contributions across a wiki farm
 *
 * Inspired by Luxo's tool aka GUC.
 *
 * @file
 * @ingroup Extensions
 * @author Kunal Mehta
 * @license GPLv2 or higher
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

/*
 * Wikis to search
 * If empty, defaults to $wgLocalDatabases
 * @var array of database names
 */
$wgGUCWikis = array();

$wgExtensionCredits['specialpage'][] = array(
	'path' => __FILE__,
	'name' => 'GlobalContributions',
	'author' => 'Kunal Mehta',
	'url' => 'https://www.mediawiki.org/wiki/Extension:GlobalContributions',
	'descriptionmsg' => 'guc-desc',
	'version' => '0.1',
);

$wgAutoloadClasses['GlobalUserContribs'] = __DIR__ . '/GlobalContributions.body.php';
$wgAutoloadClasses['SpecialGlobalContributions'] = __DIR__ . '/SpecialGlobalContributions.php';

$wgSpecialPages['GlobalContributions'] = 'SpecialGlobalContributions';
$wgSpecialPageGroups['GlobalContributions'] = 'users';
$wgExtensionMessagesFiles['GlobalContributions'] = __DIR__ . '/GlobalContributions.i18n.php';
$wgExtensionMessagesFiles['GlobalContributionsAlias'] = __DIR__ . '/GlobalContributions.alias.php';
