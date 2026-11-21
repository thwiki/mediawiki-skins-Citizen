<?php
declare( strict_types=1 );

namespace MediaWiki\Skins\Citizen\Hooks;

use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\Hook\SkinBuildSidebarHook;
use MediaWiki\Html\Html;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\Hook\OutputPageAfterGetHeadLinksArrayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\ResourceLoader as RL;
use MediaWiki\Skin\SkinComponentUtils;
use MediaWiki\Skins\Citizen\Menu\MenuItemDecorator;
use MediaWiki\Skins\Citizen\PreviewChannel;
use MediaWiki\Skins\Hook\SkinPageReadyConfigHook;
use Skin;

/**
 * Hooks to run relating the skin
 */
class SkinHooks implements
	BeforePageDisplayHook,
	OutputPageAfterGetHeadLinksArrayHook,
	SidebarBeforeOutputHook,
	SkinBuildSidebarHook,
	SkinPageReadyConfigHook
{
	/**
	 * Sidebar keys that name a skin-provided menu rather than one defined in
	 * MediaWiki:Sidebar. They must never be picked as the site tools menu.
	 */
	private const MAGIC_PORTLETS = [ 'SEARCH', 'TOOLBOX', 'LANGUAGES' ];
	/**
	 * Sidebar menu that holds the site-wide tools (upload file, special pages).
	 * The tools are grouped here instead of being appended to the first menu.
	 */
	public const SITE_TOOLS_MENU_ID = '站点工具';


	private static ?string $inlineScript = null;

	/**
	 * Adds the inline theme switcher script and applies the preview
	 * channel for the current request.
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		// It's better to exit before any additional check
		if ( $skin->getSkinName() !== 'citizen' ) {
			return;
		}

		if ( $out->getConfig()->get( 'CitizenEnablePreferences' ) === true ) {
			self::$inlineScript ??= Html::inlineScript(
				RL\ResourceLoader::filter(
					'minify-js',
					file_get_contents( __DIR__ . '/../../resources/skins.citizen.scripts/inline.js' )
				)
			);
			$out->addHeadItem( 'skin.citizen.inline', self::$inlineScript );
		}

		self::applyPreviewChannel( $out );
	}

	/**
	 * Resolve the preview channel for this request and apply it: pick
	 * the color-token module and stamp the generation class.
	 *
	 * Priority: ?citizenpreview= URL param > citizenpreview cookie >
	 * $wgCitizenPreview config. An explicit URL choice also writes a
	 * 24-hour cookie, so a flip takes effect on subsequent requests —
	 * exactly one token module ships per request.
	 */
	private static function applyPreviewChannel( OutputPage $out ): void {
		$request = $out->getRequest();
		$config = $out->getConfig();
		$urlVal = $request->getRawVal( PreviewChannel::REQUEST_KEY );
		$cookieVal = $request->getCookie( PreviewChannel::REQUEST_KEY );

		if ( PreviewChannel::isExplicitValue( $urlVal ) ) {
			$request->response()->setCookie(
				PreviewChannel::REQUEST_KEY,
				(string)$urlVal,
				time() + 86400
			);
		}

		if ( PreviewChannel::isPreview( $urlVal, $cookieVal, $config ) ) {
			$out->addModuleStyles( [ 'skins.citizen.tokens.new' ] );
			$out->addHtmlClasses( [ PreviewChannel::HTML_CLASS ] );
		} else {
			// citizen-v4-remove — legacy pipeline, deleted at the 4.0 flip
			$out->addModuleStyles( [ 'skins.citizen.tokens' ] );
		}
	}

	/**
	 * Replace the viewport meta tag with a more sane one
	 *
	 * @param array &$tags
	 * @param OutputPage $out
	 */
	public function onOutputPageAfterGetHeadLinksArray( &$tags, $out ): void {
		if ( $out->getSkin()->getSkinName() !== 'citizen' ) {
			return;
		}

		if ( !isset( $tags['meta-viewport'] ) ) {
			return;
		}

		/**
		 * The MW default tag was created from T258290, our changes include:
		 * Added: viewport-fit=cover - #1036
		 * Removed: user-scalable=yes - This is the default value
		 * Removed: minimum-scale=0.25 - Seems like an old workaround for iOS that is no longer needed
		 * Removed: maximum-scale=5.0 - Seems like an old workaround for iOS that is no longer needed
		 */
		$tags['meta-viewport'] = Html::element( 'meta', [
			'name' => 'viewport',
			'content' => 'width=device-width,initial-scale=1,viewport-fit=cover',
		] );
	}

	/**
	 * Modify toolbox links and relocate the site-wide tools it holds
	 * For some reason onSkinBuildSidebar was not able to get toolbox
	 * So we need to use this hook instead
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SidebarBeforeOutput
	 * @param Skin $skin
	 * @param array &$sidebar
	 */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		// Be extra safe because it might be active on other skins with caching
		if ( $skin->getSkinName() !== 'citizen' ) {
			return;
		}

		self::moveUploadToSiteTools( $skin, $sidebar );

		if ( isset( $sidebar['TOOLBOX'] ) ) {
			self::updateToolboxMenu( $sidebar );
		}
	}

	/**
	 * Move the "Upload file" link from the toolbox to the site tools menu.
	 *
	 * The item is taken from the toolbox rather than rebuilt: MediaWiki resolves
	 * $wgUploadNavigationUrl and the viewer's upload permission when it builds
	 * that entry, and this hook is the earliest one where the toolbox exists.
	 * MediaWiki omits the entry when uploads are unavailable to the viewer, so
	 * an absent entry means the menu must stay without an upload link.
	 */
	private static function moveUploadToSiteTools( Skin $skin, array &$sidebar ): void {
		$upload = $sidebar['TOOLBOX']['upload'] ?? null;
		$specialpages = $sidebar['TOOLBOX']['specialpages'] ?? null;

		if ( $upload === null && $specialpages === null ) {
			return;
		}

		unset( $sidebar['TOOLBOX']['upload'], $sidebar['TOOLBOX']['specialpages'] );

		// [thwiki] 上传文件与特殊页面合并进专用菜单，放在原 TOOLBOX（侧栏“工具”）的位置
		$menuId = self::getSiteToolsMenuId( $skin, $sidebar );
		if ( !isset( $sidebar[$menuId] ) ) {
			$newBar = [];
			foreach ( $sidebar as $key => $value ) {
				if ( $key === 'TOOLBOX' ) {
					$newBar[$menuId] = [];
				}
				$newBar[$key] = $value;
			}
			if ( !isset( $newBar[$menuId] ) ) {
				$newBar[$menuId] = [];
			}
			$sidebar = $newBar;
		}

		$items = [];
		if ( $upload !== null ) {
			$upload['msg'] = 'upload';
			$upload['icon'] = 'upload';
			$upload['link-html'] = MenuItemDecorator::getIconHtml( 'upload' );
			$items['upload'] = $upload;
		}
		if ( $specialpages !== null ) {
			$specialpages['msg'] = 'specialpages';
			$specialpages['icon'] = 'specialPages';
			$specialpages['link-html'] = MenuItemDecorator::getIconHtml( 'specialPages' );
			$items['specialpages'] = $specialpages;
		}

		foreach ( $items as $key => $value ) {
			$sidebar[$menuId][$key] = $value;
		}
	}

	/**
	 * Resolve the menu that site-wide tools are appended to.
	 *
	 * Called from both sidebar hooks, so the fallback skips the skin-provided
	 * menus that only one of them sees.
	 */
	private static function getSiteToolsMenuId( Skin $skin, array $bar ): string {
		$customSiteToolsMenuId = (string)$skin->getConfig()->get( 'CitizenGlobalToolsPortlet' );

		if ( $customSiteToolsMenuId !== '' ) {
			// remove initial p- for backward compatibility
			return str_starts_with( $customSiteToolsMenuId, 'p-' )
				? substr( $customSiteToolsMenuId, 2 )
				: $customSiteToolsMenuId;
		}

		// [thwiki] 未配置时用专用菜单，而不是追加到第一个非 magic 门户（会插到“常用”最前面）
		return self::SITE_TOOLS_MENU_ID;
	}

	/**
	 * Modify sidebar links
	 * This is cached compared to onSidebarBeforeOutput
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinBuildSidebar
	 * @param Skin $skin
	 * @param array &$bar
	 */
	public function onSkinBuildSidebar( $skin, &$bar ): void {
		// Be extra safe because it might be active on other skins with caching
		if ( $skin->getSkinName() !== 'citizen' ) {
			return;
		}

		$this->addSiteTools( $skin, $bar );

		$iconMap = [
			// TODO: Remove when we drop MW 1.43 support AND T405413 is resolved
			'n-specialpages' => 'specialPages',
			// TODO: Remove when we drop MW 1.43 support
			't-specialpages' => 'specialPages',
			// Only reachable once upload moves into the sidebar upstream (T417298);
			// until then the toolbox copy is decorated in moveUploadToSiteTools()
			't-upload' => 'upload',
		];

		foreach ( $bar as &$menu ) {
			// Sidebar links do not have a string as key, so we need to loop through each item
			foreach ( $menu as &$item ) {
				if ( isset( $item['id'] ) && array_key_exists( $item['id'], $iconMap ) ) {
					$item['icon'] = $iconMap[$item['id']];
				}

				if ( !empty( $item['icon'] ) ) {
					$item['link-html'] = MenuItemDecorator::getIconHtml( $item['icon'] );
				}
			}
		}
		unset( $menu, $item );
	}

	private function addSiteTools( Skin $skin, array &$bar ): void {
		// Do not override specialpages if it already exists (#1116)
		// TODO: Revisit in next LTS release (T333211)
		if ( version_compare( MW_VERSION, '1.44', '<' ) ) {
			$bar[self::getSiteToolsMenuId( $skin, $bar )][] = [
				'text'  => $skin->msg( 'specialpages' ),
				'href'  => SkinComponentUtils::makeSpecialUrl( 'Specialpages' ),
				'title' => $skin->msg( 'tooltip-t-specialpages' ),
				'id'    => 't-specialpages',
			];
		}
	}

	/**
	 * SkinPageReadyConfig hook handler
	 *
	 * Replace searchModule provided by skin.
	 *
	 * @since 1.36
	 * @param RL\Context $context
	 * @param mixed[] &$config Associative array of configurable options
	 * @return void This hook must not abort, it must return no value
	 */
	public function onSkinPageReadyConfig( $context, array &$config ): void {
		// It's better to exit before any additional check
		if ( $context->getSkin() !== 'citizen' ) {
			return;
		}

		// Tell the `mediawiki.page.ready` module not to wire up search.
		$config['search'] = false;
	}

	/**
	 * Update toolbox menu items
	 *
	 * @internal used inside Hooks\SkinHooks::onSidebarBeforeOutput
	 */
	private static function updateToolboxMenu( array &$links ): void {
		// Most icons are not mapped yet in the toolbox menu
		$iconMap = [
			'recentchangeslinked' => 'recentChanges',
			'print' => 'printer',
			'contributions' => 'userContributions',
			'emailuser' => 'message',
			// Extension:Cargo
			'cargo-pagevalues' => 'table',
			// Extension:CiteThisPage
			'citethispage' => 'quotes',
			// Extension:CreateRedirect
			'createredirect' => 'articleRedirect',
			// Extension:SemanticMediaWiki
			'smwbrowselink' => 'table',
			// Extension:UrlShortener
			'urlshortener' => 'link',
			'urlshortener-qrcode' => 'qrCode',
			// Extension:Wikibase
			'wikibase' => 'logoWikidata'
		];

		// Remove specialpages from toolbox as we moved it to drawer
		// TODO: Check again in the next LTS release, in case it's handled there
		unset( $links['TOOLBOX']['specialpages'] );

		MenuItemDecorator::mapIconsToMenuItems( $links, 'TOOLBOX', $iconMap );
		MenuItemDecorator::addIconsToMenuItems( $links, 'TOOLBOX' );
	}
}
