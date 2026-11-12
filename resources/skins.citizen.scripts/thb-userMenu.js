/**
 * [THBWiki] 登录用户在 Citizen 用户菜单按钮上显示头像。
 *
 * 用户菜单按钮由 templates/UserMenu.mustache 渲染，默认是通用的
 * userAvatar 图标（.citizen-ui-icon）。登录状态下把它换成站点
 * 头像（wgAvatarConfig，来自 AnalyzePage / FlowThread 配置）。
 */
( function () {
	/**
	 * @return {string|null} 头像 URL；匿名或缺少配置时返回 null
	 */
	function getAvatarUrl() {
		var userId = mw.user.getId();
		if ( !userId ) {
			return null;
		}
		var config = mw.config.get( 'wgAvatarConfig' );
		if ( !config || !config.Avatar ) {
			return null;
		}
		return config.Avatar
			.replace( /\$\{userid\}/g, userId )
			.replace( /\$\{username\}/g, mw.user.getName() );
	}

	function init() {
		var icon = document.querySelector( '.citizen-userMenu .citizen-ui-icon' );
		if ( !icon ) {
			return;
		}
		var url = getAvatarUrl();
		if ( !url ) {
			return;
		}
		var img = document.createElement( 'img' );
		img.className = 'thb-useravatar';
		img.src = url;
		img.alt = '';
		img.width = 20;
		img.height = 20;
		icon.replaceWith( img );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
