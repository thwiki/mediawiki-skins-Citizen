/**
 * Command palette mode for searching and exploring categories.
 *
 * States:
 *  - root, empty input: lists the current page's categories from
 *    mw.config.get('wgCategories'), no API call.
 *  - root, typed query: prefixsearch on the Category namespace.
 *  - drilled, empty input: categorymembers of the deepest stack entry,
 *    subcategories first then pages.
 *  - drilled, typed query: same fetch, filtered client-side by
 *    case-insensitive title startsWith.
 */
const { cdxIconTag, cdxIconArticle, cdxIconEdit } = require( '../icons.json' );
const isAbortError = require( '../utils/isAbortError.js' );
const config = require( '../config.json' );
const { defineMode } = require( '../services/defineMode.js' );

const CATEGORY_NS_ID = 14;
const CATEGORY_NS_NAME = 'Category';

/**
 * Normalize a category title (bare, localized or canonical) into a
 * mw.Title instance in the Category namespace.
 *
 * @param {string} value
 * @return {mw.Title|null}
 */
function normalizeCategoryTitle( value ) {
	const text = String( value || '' ).trim();
	if ( !text ) {
		return null;
	}
	try {
		return mw.Title.newFromText( text, CATEGORY_NS_ID ) ||
			mw.Title.makeTitle( CATEGORY_NS_ID, text );
	} catch ( error ) {
		return null;
	}
}

/**
 * Build a CommandPaletteItem for a category result (drillable).
 *
 * @param {string} title Category title, with or without the local prefix.
 * @return {Object}
 */
function adaptCategoryItem( title ) {
	// No url is set on category results: drilling, not navigation, is the
	// primary action. Setting url would render the item as an <a> tag and
	// the browser would follow it on mouse click before the Vue handler
	// could fire pushModeContext. The "view" action below restores the
	// path to the actual Category: page.
	const titleObj = normalizeCategoryTitle( title );
	const bareTitle = titleObj ? titleObj.getMainText() : String( title || '' );
	const categoryTitle = titleObj ?
		titleObj.getPrefixedText() :
		CATEGORY_NS_NAME + ':' + bareTitle;
	return {
		id: 'citizen-command-palette-item-category-' + bareTitle,
		thumbnailIcon: cdxIconTag,
		type: 'category',
		label: bareTitle,
		value: categoryTitle,
		highlightQuery: true,
		actions: [
			{
				id: 'view',
				label: mw.message( 'citizen-command-palette-action-view' ).text(),
				icon: cdxIconArticle,
				url: mw.util.getUrl( categoryTitle )
			},
			{
				id: 'edit',
				label: mw.message( 'edit' ).text(),
				icon: cdxIconEdit,
				url: mw.util.getUrl( categoryTitle, { action: 'edit' } )
			}
		]
	};
}

/**
 * Build a CommandPaletteItem for a member page (terminal).
 *
 * @param {{title: string}} apiPage
 * @return {Object}
 */
function adaptPageItem( apiPage ) {
	return {
		id: 'citizen-command-palette-item-categorymember-' + apiPage.title,
		thumbnailIcon: cdxIconArticle,
		type: 'page',
		label: apiPage.title,
		url: mw.util.getUrl( apiPage.title ),
		highlightQuery: true,
		actions: [
			{
				id: 'edit',
				label: mw.message( 'edit' ).text(),
				icon: cdxIconEdit,
				url: mw.util.getUrl( apiPage.title, { action: 'edit' } )
			}
		]
	};
}

/**
 * Filter results client-side by case-insensitive title prefix.
 *
 * @param {Array<Object>} items
 * @param {string} subQuery
 * @return {Array<Object>}
 */
function filterByPrefix( items, subQuery ) {
	if ( !subQuery ) {
		return items;
	}
	const lower = subQuery.toLowerCase();
	return items.filter( ( item ) => item.label.toLowerCase().startsWith( lower ) );
}

/**
 * Returns the current page's categories from MediaWiki config.
 *
 * @return {Array<Object>}
 */
function getCurrentPageCategoryItems() {
	const cats = mw.config.get( 'wgCategories' ) || [];
	return cats.map( adaptCategoryItem );
}

/**
 * Factory for the category mode.
 *
 * @param {typeof mw.Api} ApiConstructor mw.Api constructor.
 * @return {Object}
 */
function createCategoryMode( ApiConstructor ) {
	async function searchCategories( subQuery, signal ) {
		const api = new ApiConstructor();
		try {
			const data = await api.get( {
				action: 'query',
				format: 'json',
				list: 'prefixsearch',
				pssearch: subQuery,
				psnamespace: CATEGORY_NS_ID,
				pslimit: 10,
				maxage: config.wgSearchSuggestCacheExpiry,
				smaxage: config.wgSearchSuggestCacheExpiry
			}, { signal } );

			const hits = data?.query?.prefixsearch || [];
			return hits.map( ( hit ) => adaptCategoryItem( hit.title ) );
		} catch ( error ) {
			if ( isAbortError( error ) ) {
				return [];
			}
			mw.log.error( '[commandPalette] Category prefixsearch failed:', error );
			return [];
		}
	}

	async function fetchMembers( categoryTitle, signal ) {
		const api = new ApiConstructor();
		const titleObj = normalizeCategoryTitle( categoryTitle );
		const cmtitle = titleObj ? titleObj.getPrefixedText() : categoryTitle;
		try {
			const data = await api.get( {
				action: 'query',
				format: 'json',
				list: 'categorymembers',
				cmtitle: cmtitle,
				cmtype: 'subcat|page',
				cmlimit: 50,
				maxage: config.wgSearchSuggestCacheExpiry,
				smaxage: config.wgSearchSuggestCacheExpiry
			}, { signal } );

			const members = data?.query?.categorymembers || [];
			const subcats = [];
			const pages = [];
			members.forEach( ( m ) => {
				if ( m.ns === CATEGORY_NS_ID ) {
					subcats.push( adaptCategoryItem( m.title ) );
				} else {
					pages.push( adaptPageItem( m ) );
				}
			} );
			return subcats.concat( pages );
		} catch ( error ) {
			if ( isAbortError( error ) ) {
				return [];
			}
			mw.log.error( '[commandPalette] Category members fetch failed:', error );
			return [];
		}
	}

	async function getResults( subQuery, signal, _tokens, modeContext ) {
		const context = Array.isArray( modeContext ) ? modeContext : [];
		const drilled = context.length > 0 ? context[ context.length - 1 ] : null;

		if ( !drilled ) {
			if ( !subQuery ) {
				return getCurrentPageCategoryItems();
			}
			return await searchCategories( subQuery, signal );
		}

		const members = await fetchMembers( drilled.title, signal );
		return filterByPrefix( members, subQuery );
	}

	function onResultSelect( item ) {
		if ( item.type === 'category' ) {
			return { action: 'pushModeContext', payload: { title: item.value } };
		}
		if ( item.url ) {
			return { action: 'navigate', payload: item.url };
		}
		return { action: 'none' };
	}

	function headerLabel( modeContext ) {
		// At root (empty stack) return null so the header falls back to
		// the more action-oriented placeholder. The breadcrumb only adds
		// value once the user has drilled in.
		if ( !modeContext || modeContext.length === 0 ) {
			return null;
		}
		const root = mw.message( 'citizen-command-palette-mode-category-breadcrumb-root' ).text();
		// Show the bare title in the breadcrumb; context entries keep the
		// prefixed title so drilling and URL building stay namespace aware.
		const segments = [ root ].concat( modeContext.map( ( c ) => {
			const title = normalizeCategoryTitle( c.title );
			return title ? title.getMainText() : c.title;
		} ) );
		return segments.join( ' / ' );
	}

	return defineMode( {
		id: 'category',
		triggers: [ '/cat:', '#' ],
		label: mw.message( 'citizen-command-palette-command-category-label' ).text(),
		description: mw.message( 'citizen-command-palette-command-category-description' ).text(),
		placeholder: mw.message( 'citizen-command-palette-mode-category-placeholder' ).text(),
		icon: cdxIconTag,
		compactResults: true,
		emptyState: {
			title: mw.message( 'citizen-command-palette-mode-category-empty-title' ).text(),
			description: mw.message( 'citizen-command-palette-mode-category-empty-description' ).text(),
			icon: cdxIconTag
		},
		noResults() {
			return {
				title: mw.message( 'citizen-command-palette-mode-category-noresults-title' ).text(),
				description: mw.message( 'citizen-command-palette-mode-category-noresults-description' ).text(),
				icon: cdxIconTag
			};
		},
		help: {
			description: 'citizen-command-palette-mode-category-description-help'
		},
		getResults,
		onResultSelect,
		headerLabel
	} );
}

module.exports = createCategoryMode;
