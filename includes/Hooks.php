<?php
/**
 * Hooks for Disambiguator extension
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\Disambiguator;

use Config;
use ExtensionRegistry;
use LinksUpdate;
use MediaWiki\ChangeTags\Hook\ChangeTagsListActiveHook;
use MediaWiki\ChangeTags\Hook\ListDefinedTagsHook;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Extension\Disambiguator\Specials\SpecialDisambiguationPageLinks;
use MediaWiki\Extension\Disambiguator\Specials\SpecialDisambiguationPages;
use MediaWiki\Hook\AncientPagesQueryHook;
use MediaWiki\Hook\EditPage__showEditForm_initialHook;
use MediaWiki\Hook\GetDoubleUnderscoreIDsHook;
use MediaWiki\Hook\GetLinkColoursHook;
use MediaWiki\Hook\LonelyPagesQueryHook;
use MediaWiki\Hook\RandomPageQueryHook;
use MediaWiki\Hook\RecentChange_saveHook;
use MediaWiki\Hook\ShortPagesQueryHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\Hook\WgQueryPagesHook;
use MediaWiki\Title\Title;
use OutputPage;

// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
class Hooks implements
	ListDefinedTagsHook,
	ChangeTagsListActiveHook,
	RecentChange_saveHook,
	EditPage__showEditForm_initialHook,
	GetDoubleUnderscoreIDsHook,
	WgQueryPagesHook,
	AncientPagesQueryHook,
	LonelyPagesQueryHook,
	ShortPagesQueryHook,
	RandomPageQueryHook,
	GetLinkColoursHook
{

	private const TAGS = [
		'disambiguator-link-added'
	];

	/** @var Lookup */
	private $lookup;

	/** @var bool */
	private $showNotifications;

	/** @var bool[] Rev IDs are used as keys */
	private static $revsToTag = [];

	/**
	 * @param Lookup $lookup
	 * @param Config $options
	 */
	public function __construct( Lookup $lookup, Config $options ) {
		$this->lookup = $lookup;
		$this->showNotifications = $options->get( 'DisambiguatorNotifications' );
	}

	/**
	 * @param array &$doubleUnderscoreIDs
	 */
	public function onGetDoubleUnderscoreIDs( &$doubleUnderscoreIDs ) {
		$doubleUnderscoreIDs[] = 'disambiguation';
	}

	/**
	 * Add the Disambiguator special pages to the list of QueryPages. This
	 * allows direct access via the API.
	 * @param array &$queryPages
	 */
	public function onWgQueryPages( &$queryPages ) {
		$queryPages[] = [ SpecialDisambiguationPages::class, 'DisambiguationPages' ];
		$queryPages[] = [ SpecialDisambiguationPageLinks::class, 'DisambiguationPageLinks' ];
	}

	/**
	 * Modify query parameters to ignore disambiguation pages
	 * @param array &$tables
	 * @param array &$conds
	 * @param array &$joinConds
	 */
	private static function excludeDisambiguationPages( &$tables, &$conds, &$joinConds ) {
		$tables['disambig_props'] = 'page_props';
		$conds['disambig_props.pp_page'] = null;
		$joinConds['disambig_props'] = [
			'LEFT JOIN', [
				'page_id = disambig_props.pp_page',
				'disambig_props.pp_propname' => 'disambiguation'
			]
		];
	}

	/**
	 * Modify the Special:AncientPages query to ignore disambiguation pages
	 * @param array &$tables
	 * @param array &$conds
	 * @param array &$joinConds
	 */
	public function onAncientPagesQuery( &$tables, &$conds, &$joinConds ) {
		self::excludeDisambiguationPages( $tables, $conds, $joinConds );
	}

	/**
	 * Modify the Special:LonelyPages query to ignore disambiguation pages
	 * @param array &$tables
	 * @param array &$conds
	 * @param array &$joinConds
	 */
	public function onLonelyPagesQuery( &$tables, &$conds, &$joinConds ) {
		self::excludeDisambiguationPages( $tables, $conds, $joinConds );
	}

	/**
	 * Modify the Special:ShortPages query to ignore disambiguation pages
	 * @param array &$tables
	 * @param array &$conds
	 * @param array &$joinConds
	 * @param array &$options
	 */
	public function onShortPagesQuery( &$tables, &$conds, &$joinConds, &$options ) {
		self::excludeDisambiguationPages( $tables, $conds, $joinConds );
	}

	/**
	 * Modify the Special:Random query to ignore disambiguation pages
	 * @param array &$tables
	 * @param array &$conds
	 * @param array &$joinConds
	 */
	public function onRandomPageQuery( &$tables, &$conds, &$joinConds ) {
		self::excludeDisambiguationPages( $tables, $conds, $joinConds );
	}

	/**
	 * Convenience function for testing whether or not a page is a disambiguation page
	 *
	 * @deprecated Use DisambiguatorLookup service
	 *
	 * @param Title $title object of a page
	 * @param bool $includeRedirects Whether to consider redirects to disambiguations as
	 *   disambiguations.
	 * @return bool
	 */
	public static function isDisambiguationPage( Title $title, $includeRedirects = true ) {
		return MediaWikiServices::getInstance()->getService( 'DisambiguatorLookup' )
			->isDisambiguationPage( $title, $includeRedirects );
	}

	/**
	 * Add 'mw-disambig' CSS class to links to disambiguation pages.
	 * @param array $pageIdToDbKey Prefixed DB keys of the pages linked to, indexed by page_id
	 * @param array &$colours CSS classes, indexed by prefixed DB keys
	 * @param Title $title
	 */
	public function onGetLinkColours( $pageIdToDbKey, &$colours, $title ) {
		global $wgDisambiguatorIndicateLinks;
		if ( !$wgDisambiguatorIndicateLinks ) {
			return;
		}

		$pageIds = MediaWikiServices::getInstance()->getService( 'DisambiguatorLookup' )
			->filterDisambiguationPageIds( array_keys( $pageIdToDbKey ) );

		foreach ( $pageIds as $pageId ) {
			if ( isset( $colours[ $pageIdToDbKey[$pageId] ] ) ) {
				$colours[ $pageIdToDbKey[$pageId] ] .= ' mw-disambig';
			} else {
				$colours[ $pageIdToDbKey[$pageId] ] = 'mw-disambig';
			}
		}
	}

	/**
	 * Implements the ListDefinedTags hook, to populate core
	 * Special:Tags with the change tags used by Disambiguator.
	 *
	 * @param string[] &$tags List of all active tags. Append to this array.
	 */
	public function onListDefinedTags( &$tags ) {
		$tags = array_merge( $tags, static::TAGS );
	}

	/**
	 * Implements the ChangeTagsListActive hook, to mark the
	 * Disambiguator change tag as active.
	 *
	 * @param array &$tags Available change tags.
	 */
	public function onChangeTagsListActive( &$tags ) {
		$tags = array_merge( $tags, static::TAGS );
	}

	/**
	 * Add a change tag to edits that introduce links to disambiguation pages.
	 *
	 * @param LinksUpdate $linksUpdate
	 */
	public function onLinksUpdateComplete( LinksUpdate $linksUpdate ) {
		$addedLinks = $linksUpdate->getAddedLinks();

		if ( !$addedLinks ) {
			return;
		}

		$pageIds = array_map( static function ( Title $title ) {
			return $title->getId();
		}, $addedLinks );

		$disambigs = $this->lookup->filterDisambiguationPageIds( $pageIds, true );
		$rev = $linksUpdate->getRevisionRecord();

		if ( $disambigs && $rev ) {
			self::$revsToTag[$rev->getId()] = true;
		}
	}

	/**
	 * Add the modules to the edit form.
	 *
	 * @param EditPage $editor
	 * @param OutputPage $out
	 */
	public function onEditPage__showEditForm_initial( $editor, $out ) {
		if ( $editor->contentModel !== CONTENT_MODEL_WIKITEXT || !$this->showNotifications ) {
			return;
		}

		// Add modules.
		$services = MediaWikiServices::getInstance();
		$isMobileView = ExtensionRegistry::getInstance()->isLoaded( 'MobileFrontend' ) &&
			$services->getService( 'MobileFrontend.Context' )->shouldDisplayMobileView();
		if ( !$isMobileView ) {
			$out->addModules( 'ext.disambiguator' );
		}
	}

	/**
	 * @inheritDoc
	 */
	// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	public function onRecentChange_save( $recentChange ) {
		$revId = $recentChange->getAttribute( 'rc_this_oldid' );
		if ( $recentChange->getAttribute( 'rc_log_type' ) ) {
			return;
		}
		if ( isset( self::$revsToTag[$revId] ) ) {
			$recentChange->addTags( self::TAGS );
			unset( self::$revsToTag[$revId] );
		}
	}
}
