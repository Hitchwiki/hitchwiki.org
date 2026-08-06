<?php

# Further documentation for configuration settings may be found at:
# https://www.mediawiki.org/wiki/Manual:Configuration_settings

## MAINTENANCE MODE: Uncomment the following line for read-only mode of the wiki (for maintenance, etc.)
# $wgReadOnly = '<div class="alert" style="font-size:25px;line-height:35px;">' . '<strong>Hitchwiki is read-only currently as we are updating the website over the weekend. Thanks for the patience and happy hitching!</strong>' . '</div';

# Protect against web entry
if (!defined('MEDIAWIKI')) {
	exit;
}

## Load environment variables
$envPaths = [
	dirname(__DIR__),
	dirname(__DIR__) . '/private',
	dirname($_SERVER['DOCUMENT_ROOT']),
	dirname($_SERVER['DOCUMENT_ROOT']) . '/private',
];

$envPath = null;
foreach ($envPaths as $path) {
	if (file_exists($path . '/.env')) {
		$envPath = $path;
		break;
	}
}

if ($envPath) {
	$dotenv = Dotenv\Dotenv::createImmutable($envPath);
	$dotenv->safeLoad();
}

## Set up multiple languages through a Wiki family
# Available domain names
$hwLanguages = [
	'bg' => 'Hitchwiki',
	'cs' => 'Autostopwiki',
	'de' => 'Tramperwiki',
	'en' => 'Hitchwiki',
	'es' => 'Autostopwiki',
	'fi' => 'Liftariwiki',
	'fr' => 'Hitchwiki',
	'he' => 'Hitchwiki',
	'hr' => 'Hitchwiki',
	'nl' => 'Hitchwiki',
	'pl' => 'Autostopwiki',
	'pt' => 'Hitchwiki',
	'ro' => 'Hitchwiki',
	'ru' => 'Hitchwiki',
	'tr' => 'Otostopviki',
	'zh' => 'Hitchwiki',
	'it' => 'Hitchwiki',
	'lt' => 'Hitchwiki',
	'uk' => 'Hitchwiki',
	'sk' => 'Autostopwiki',
	'fa' => 'Hitchwiki',
	'ka' => 'Hitchwiki',
	'el' => 'Hitchwiki',
	'hu' => 'Hitchwiki',
	'sv' => 'Hitchwiki',
	'no' => 'Hitchwiki',
	'da' => 'Hitchwiki',
	'sr' => 'Hitchwiki',
	'sl' => 'Hitchwiki',
	'et' => 'Hitchwiki',
	'lv' => 'Hitchwiki',
	'ja' => 'Hitchwiki',
	'ar' => 'Hitchwiki',
	'mn' => 'Hitchwiki',
];

# Create a string of valid language codes from $hwLanguages
$hwLangCodes = implode('|', array_keys($hwLanguages));

# Set wiki to default language
$defaultLang = $_ENV['MEDIAWIKI_DEFAULT_LANG'] ?? 'en';
$wikiID = $defaultLang;

# Detect language from URL
if (
	isset($_SERVER['REQUEST_URI']) &&
	preg_match("!^/($hwLangCodes)(/.*)?$!", $_SERVER['REQUEST_URI'], $matches)
) {
	$wikiID = $matches[1];
}

# Override with MW_DB if set (--wiki [lang] for maintenance scripts)
if (defined('MW_DB')) {
	$wikiID = MW_DB;
} elseif (isset($_SERVER['MW_DB'])) {
	$wikiID = $_SERVER['MW_DB'];
}

# Validate wiki exists
if (!isset($hwLanguages[$wikiID])) {
	die('Unknown wiki.');
}

## Uncomment this to disable output compression
# $wgDisableOutputCompression = true;

$wgSitename = $hwLanguages[$wikiID] ?? 'Hitchwiki';

## The project namespace is "Hitchwiki:" on every wiki, even where the site is
## branded Tramperwiki/Autostopwiki/Liftariwiki/Otostopviki. Without this it
## follows $wgSitename, so a family-wide link such as [[Hitchwiki:About]] or
## [[Hitchwiki:Community Portal]] silently lands in the *main* namespace on
## those wikis and can never resolve.
$wgMetaNamespace = 'Hitchwiki';

## Pages created before that change live under the old project namespace name,
## and the main pages still link to them ([[Tramperwiki:Übersetzung]]). Keeping
## every historical name as an alias means those titles keep resolving. The talk
## forms are the localised names MediaWiki derived from the old $wgSitename, so
## they are listed rather than computed.
$hwOldProjectNamespaces = [
	'cs' => ['Autostopwiki', 'Diskuse k Autostopwiki'],
	'de' => ['Tramperwiki', 'Tramperwiki Diskussion'],
	'es' => ['Autostopwiki', 'Autostopwiki discusión'],
	'fi' => ['Liftariwiki', 'Keskustelu Liftariwikistä'],
	'pl' => ['Autostopwiki', 'Dyskusja Autostopwiki'],
	'sk' => ['Autostopwiki', 'Diskusia k Autostopwiki'],
	'tr' => ['Otostopviki', 'Otostopviki tartışma'],
];
if (isset($hwOldProjectNamespaces[$wikiID])) {
	[$hwOldProject, $hwOldProjectTalk] = $hwOldProjectNamespaces[$wikiID];
	$wgNamespaceAliases[strtr($hwOldProject, ' ', '_')] = NS_PROJECT;
	$wgNamespaceAliases[strtr($hwOldProjectTalk, ' ', '_')] = NS_PROJECT_TALK;
}

## The URL base path to the directory containing the wiki;
## defaults for all runtime URL paths are based off of this.
## For more information on customizing the URLs
## (like /w/index.php/Page_title to /wiki/Page_title) please see:
## https://www.mediawiki.org/wiki/Manual:Short_URL
$wgScriptPath = "/$wikiID";

## The protocol and server name to use in fully-qualified URLs
$wgServer = $_ENV['MEDIAWIKI_SERVER'];
$wgArticlePath = "/$wikiID/$1";

## The URL path to the logo.  Make sure you change this from the default,
## or else you'll overwrite your logo when you upgrade!
$wgLogo = "$wgScriptPath/images/logo.png";

## Cookie settings
# Defaults
$wgCookieDomain = "." . $_ENV['MEDIAWIKI_SITE_DOMAIN'];
$wgCookiePrefix = "hw_";

# Allow overriding through environment variables
if (!empty($_ENV['MEDIAWIKI_COOKIE_DOMAIN'])) {
	$wgCookieDomain = $_ENV['MEDIAWIKI_COOKIE_DOMAIN'];
}

if (!empty($_ENV['MEDIAWIKI_COOKIE_PREFIX'])) {
	$wgCookiePrefix = $_ENV['MEDIAWIKI_COOKIE_PREFIX'];
}

## UPO means: this is also a user preference option

$wgEnableEmail = true;
$wgEnableUserEmail = false; # UPO

$wgEmergencyContact = $_ENV['MEDIAWIKI_EMAIL_CONTACT'];
$wgPasswordSender = $_ENV['MEDIAWIKI_EMAIL_SENDER'];

$wgEnotifUserTalk = true; # UPO
$wgEnotifWatchlist = true; # UPO
$wgEmailAuthentication = true;

## SMTP settings
$wgSMTP = [
	'host' => $_ENV['MEDIAWIKI_SMTP_HOST'],     // could also be an IP address. Where the SMTP server is located
	'IDHost' => $_ENV['MEDIAWIKI_SMTP_DOMAIN'], // Generally this will be the domain name of your website
	'port' => $_ENV['MEDIAWIKI_SMTP_PORT'],     // Port to use when connecting to the SMTP server (587 or alternatively 2525)
	'auth' => !empty($_ENV['MEDIAWIKI_SMTP_USER']) || !empty($_ENV['MEDIAWIKI_SMTP_PASS']), // Should we use SMTP authentication (true or false)
	'username' => $_ENV['MEDIAWIKI_SMTP_USER'], // Username to use for SMTP authentication (if being used)
	'password' => $_ENV['MEDIAWIKI_SMTP_PASS'], // Password to use for SMTP authentication (if being used)
];

## Database settings
$wgDBtype = "mysql";
$wgDBserver = $_ENV['MEDIAWIKI_DB_HOST'];
$wgDBport = $_ENV['MEDIAWIKI_DB_PORT'];
$wgDBname = $_ENV['MEDIAWIKI_DB_NAME'] . "_$wikiID";
$wgDBuser = $_ENV['MEDIAWIKI_DB_USER'];
$wgDBpassword = $_ENV['MEDIAWIKI_DB_PASSWORD'];

## Error Logging
$wgDBerrorLog = "/var/log/mediawiki/hitchwiki-db-error.log";

## Shared settings
$wgSharedDB = $_ENV['MEDIAWIKI_DB_NAME'] . "_" . $_ENV['MEDIAWIKI_DEFAULT_LANG'];
$wgSharedUploadPath = "$wgScriptPath/images/$defaultLang";
$wgSharedUploadDirectory = "$IP/images/$defaultLang";
$wgSharedUploadDBname = $wgSharedDB;

# MySQL specific settings
$wgDBprefix = "";

# MySQL table options to use during installation or update
$wgDBTableOptions = "ENGINE=InnoDB, DEFAULT CHARSET=binary";

## Shared memory settings
$wgMainCacheType = CACHE_NONE;
$wgMemCachedServers = [];

## To enable image uploads, make sure the 'images' directory
## is writable, then set this to true:
## Uploads only happen on the default-language (en) wiki now, so every image
## lives in one place (images/en). Non-default wikis read en's files via the
## ForeignDBRepo below and send users who try to upload to en's Special:Upload.
$wgEnableUploads = ( $wikiID === $defaultLang );
$wgUseImageMagick = true;
$wgImageMagickConvertCommand = "/usr/bin/convert";
if ( $wikiID !== $defaultLang ) {
	$wgUploadNavigationUrl = "$wgServer/$defaultLang/Special:Upload";
}

# Same idea for user pages: a contributor is one person across the whole family
# (the user table is shared, see $wgSharedDB above), so there is one profile and
# one talk page for them, on the English wiki. /it/Utente:X and
# /de/Benutzer_Diskussion:X redirect to /en/User:X and /en/User_talk:X instead of
# being up to 34 separate pages that nobody keeps in sync.
#
# Only base pages redirect. Subpages deliberately stay local: User:X/common.js
# and User:X/common.css are loaded per-wiki by ResourceLoader and would stop
# working anywhere else, and sandboxes/drafts belong to the wiki they were
# written on.
#
# Views only. action=edit, action=history, ?redirect=no, &oldid= and &diff= all
# still reach the local page, so the pre-existing local user pages (310 of them
# when this was introduced) and their attribution history remain reachable.
#
# 302, not 301: a permanent redirect gets cached by browsers and by Cloudflare in
# front of us, which would make this very awkward to walk back. Switch to 301
# once the arrangement has settled.
if ( $wikiID !== $defaultLang ) {
	$wgHooks['MediaWikiPerformAction'][] = static function (
		$output, $article, $title, $user, $request, $mediaWiki
	) use ( $defaultLang ) {
		if ( !$title->inNamespaces( NS_USER, NS_USER_TALK ) ) {
			return true;
		}
		if ( $request->getVal( 'action', 'view' ) !== 'view' ) {
			return true;
		}
		# Explicit history/diff/no-redirect browsing keeps working locally.
		if ( $request->getCheck( 'redirect' ) || $request->getCheck( 'oldid' )
			|| $request->getCheck( 'diff' )
		) {
			return true;
		}
		# Subpages stay put (see above).
		if ( str_contains( $title->getDBkey(), '/' ) ) {
			return true;
		}

		$prefix = $title->getNamespace() === NS_USER ? 'User:' : 'User_talk:';
		$output->redirect(
			$output->getConfig()->get( MediaWiki\MainConfigNames::Server )
				. "/$defaultLang/" . $prefix . wfUrlencode( $title->getDBkey() ),
			'302'
		);

		return false;
	};
}

# The front pages of all 34 wikis are generated from one shared template
# (tools/main_page_template.wikitext + tools/main_page_i18n/<lang>.json, pushed by
# tools/build_main_pages.py) and are sysop-protected, because a hand edit on one of
# them reaches only that language and is overwritten by the next push.
#
# Admins opening the edit form see MediaWiki:Editnotice-0-<page>, which says where to
# make the change instead. Everyone else gets EditPage's view-source screen, and that
# screen renders no edit notices at all - it would leave them with nothing but the
# HTML comment at the top of the wikitext. Show them the same notice.
$wgHooks['EditPage::showReadOnlyForm:initial'][] = static function ( $editor, $out ) {
	$title = $editor->getTitle();
	if ( !$title->isMainPage() ) {
		return true;
	}
	$notice = wfMessage( 'editnotice-0-' . strtr( $title->getDBkey(), '/', '-' ) )
		->page( $title );
	if ( $notice->exists() ) {
		$out->addHTML( $notice->parseAsBlock() );
	}

	return true;
};

# Upload Paths
$wgUploadPath = "$wgScriptPath/images/$wikiID";
$wgUploadDirectory = "$IP/images/$wikiID";

# Base Repository
$wgRepositoryBaseUrl = "$wgServer/$defaultLang/Image:";

# Share files uploaded on the default-language wiki with all other language wikis.
# Non-default wikis read en's image/oldimage tables and serve files from en's images dir.
if ( $wikiID !== $defaultLang ) {
	$wgForeignFileRepos[] = [
		'class'                  => ForeignDBRepo::class,
		'name'                   => 'shared',
		'directory'              => "$IP/images/$defaultLang",
		'url'                    => "$wgScriptPath/images/$defaultLang",
		'hashLevels'             => 2,
		'thumbScriptUrl'         => false,
		'transformVia404'        => false,
		'hasSharedCache'         => true,
		'descBaseUrl'            => "$wgServer/$defaultLang/wiki/File:",
		'scriptDirUrl'           => "$wgServer/$defaultLang/w",
		'fetchDescription'       => true,
		'descriptionCacheExpiry' => 3600,

		'dbType'                 => $wgDBtype,
		'dbServer'               => $wgDBserver,
		'dbUser'                 => $wgDBuser,
		'dbPassword'             => $wgDBpassword,
		'dbName'                 => $wgSharedDB,
		'dbFlags'                => DBO_DEFAULT,
		'tablePrefix'            => '',
	];
}

# InstantCommons allows wiki to use images from https://commons.wikimedia.org
$wgUseInstantCommons = true;

# Periodically send a pingback to https://www.mediawiki.org/ with basic data
# about this MediaWiki instance. The Wikimedia Foundation shares this data
# with MediaWiki developers to help guide future development efforts.
$wgPingback = true;

## If you use ImageMagick (or any other shell command) on a
## Linux server, this will need to be set to the name of an
## available UTF-8 locale
$wgShellLocale = "en_US.utf8";

## Set $wgCacheDirectory to a writable directory on the web server
## to make your wiki go slightly faster. The directory should not
## be publically accessible from the web.
#$wgCacheDirectory = "$IP/cache";

# Site language code, should be one of the list in ./languages/data/Names.php
$wgLanguageCode = $wikiID;

$wgSecretKey = $_ENV['MEDIAWIKI_SECRET_KEY'];

# Changing this will log out all existing sessions.
$wgAuthenticationTokenVersion = "1";

# Site upgrade key. Must be set to a string (default provided) to turn on the
# web installer while LocalSettings.php is in place
$wgUpgradeKey = $_ENV['MEDIAWIKI_UPGRADE_KEY'];

## For attaching licensing metadata to pages, and displaying an
## appropriate copyright notice / icon. GNU Free Documentation
## License and Creative Commons licenses are supported so far.
$wgRightsPage = ""; # Set to the title of a wiki page that describes your license/copyright
$wgRightsUrl = "https://creativecommons.org/licenses/by-sa/4.0/";
$wgRightsText = "Creative Commons Attribution-Share Alike"; # TODO: Is this really correct? Don't we need the version?
$wgRightsIcon = "$wgScriptPath/resources/assets/licenses/cc-by-sa.png";

# Path to the GNU diff3 utility. Used for conflict resolution.
$wgDiff3 = "/usr/bin/diff3";

## Enabled skins and configuration.
# Minerva Neue (mobile)
wfLoadSkin('MinervaNeue');
$wgMinervaNightMode['base'] = true;

# Vector + Vector 2022
wfLoadSkin('Vector');
$wgVectorNightMode['logged_in'] = true;
$wgVectorNightMode['logged_out'] = true;

## Default skin: you can change the default skin. Use the internal symbolic names, i.e. 'vector', 'monobook'
$wgDefaultSkin = "vector";

# Persistent sidebar Table of Contents for legacy Vector.
# Legacy Vector renders the TOC as an inline block in the article body; this
# module (data/hitchwiki-toc.js + .css) relocates it into the left sidebar so
# every page has an always-visible, Wikipedia-style contents list without
# needing __TOC__. Loaded family-wide from here so it can't be forgotten on a
# single language wiki. Only applies to the desktop Vector skin (Minerva has
# its own mobile TOC).
$wgResourceModules['hitchwiki.sidebarToc'] = [
	'localBasePath' => "$IP/data",
	'remoteBasePath' => "$wgResourceBasePath/data",
	'scripts' => 'hitchwiki-toc.js',
	'styles' => 'hitchwiki-toc.css',
];
$wgHooks['BeforePageDisplay'][] = function ( $out, $skin ) {
	if ( $skin->getSkinName() === 'vector' ) {
		$out->addModules( 'hitchwiki.sidebarToc' );
	}
};

# Family-wide site JavaScript (infobox maps, the Special:Block defaults, the
# coordinate and "add your own experience" prompts, the user-page Maps banner).
# This used to be MediaWiki:Common.js on each wiki separately: 34 copies that had
# drifted into six different versions, and 26 of them had lost the infobox map
# code entirely, so those wikis printed the raw "<map lat=… />" tag where a map
# belonged. Keeping it in data/hitchwiki-common.js means one source of truth that
# cannot be forgotten on a single language wiki. Every wiki's own
# MediaWiki:Common.js is still loaded by core on top of this module, so
# wiki-specific JavaScript stays possible without touching this file.
$wgResourceModules['hitchwiki.common'] = [
	'localBasePath' => "$IP/data",
	'remoteBasePath' => "$wgResourceBasePath/data",
	'scripts' => 'hitchwiki-common.js',
	'styles' => 'hitchwiki-common.css',
	'dependencies' => [ 'mediawiki.util' ],
];
$wgHooks['BeforePageDisplay'][] = static function ( $out, $skin ) {
	$out->addModules( 'hitchwiki.common' );
};

# Footer "places" links (Privacy policy / About / Legal Notice / Volunteer).
# Core builds these from MediaWiki:Privacypage, :Aboutpage and :Disclaimerpage,
# which on every non-English wiki point at a translated Project: page that does
# not actually exist. Instead every wiki links to the one English page, while the
# link *label* stays in the reader's interface language. "Volunteer" is a new
# entry (English label everywhere) pointing at the English Roles article.
$hwFooterPlaces = [
	'privacy'     => [ 'msg' => 'privacy',     'page' => 'Hitchwiki:Privacy_policy' ],
	'about'       => [ 'msg' => 'aboutsite',   'page' => 'Hitchwiki:About' ],
	'disclaimers' => [ 'msg' => 'disclaimers', 'page' => 'Hitchwiki:Legal_Notice' ],
	'volunteer'   => [ 'text' => 'Volunteer',  'page' => 'Roles' ],
];
$wgHooks['SkinAddFooterLinks'][] = function ( $skin, $key, &$footerItems )
	use ( $hwFooterPlaces, $wgServer, $defaultLang ) {
	if ( $key !== 'places' ) {
		return;
	}
	foreach ( $hwFooterPlaces as $id => $link ) {
		$footerItems[$id] = MediaWiki\Html\Html::element(
			'a',
			[ 'href' => "$wgServer/$defaultLang/" . $link['page'] ],
			$link['text'] ?? $skin->msg( $link['msg'] )->text()
		);
	}
};

## Enabled extensions and configuration.
wfLoadExtension('DismissableSiteNotice');
wfLoadExtension('Echo');
$wgDefaultUserOptions['echo-subscriptions-email-edit-user-talk'] = true;
wfLoadExtension('ExternalData');
wfLoadExtension('ParserFunctions');
wfLoadExtension('GeoCrumbs');
wfLoadExtension('HitchabilityRating');
// Per-country hitchability CSV exported by maps.hitchwiki.org. Read from the host path
// given in HITCHABILITY_RATINGS_CSV (bind-mounted into the container at the same path).
if (!empty($_ENV['HITCHABILITY_RATINGS_CSV'])) {
	$wgHitchabilityRatingDataFile = $_ENV['HITCHABILITY_RATINGS_CSV'];
}
wfLoadExtension('CheckUser');
wfLoadExtension('Nuke');

wfLoadExtension('ConfirmEdit');
wfLoadExtension('ConfirmEdit/ReCaptchaNoCaptcha');
$wgCaptchaClass = 'ReCaptchaNoCaptcha';
$wgReCaptchaSiteKey = $_ENV['RECAPTCHA_SITE_KEY'];
$wgReCaptchaSecretKey = $_ENV['RECAPTCHA_SECRET_KEY'];
$wgReCaptchaSendRemoteIP = true;
$wgCaptchaTriggers['edit'] = true;
$wgCaptchaTriggers['create'] = true;
$wgCaptchaTriggers['addurl'] = true;
$wgCaptchaTriggers['createaccount'] = true;
$wgCaptchaTriggers['badlogin'] = true;
$wgCaptchaTriggersOnNamespace[NS_TALK]['edit'] = true;
$wgCaptchaTriggersOnNamespace[NS_TALK]['create'] = true;
$wgCaptchaTriggersOnNamespace[NS_TALK]['addurl'] = true;

wfLoadExtension('WikiEditor');
$wgHiddenPrefs[] = 'usebetatoolbar';

wfLoadExtension('CodeEditor');
$wgDefaultUserOptions['usebetatoolbar'] = 1;

wfLoadExtension('CodeMirror');
$wgDefaultUserOptions['usecodemirror'] = 1;

wfLoadExtension('CollapsibleVector');
$wgCollapsibleVectorFeatures['collapsiblenav']['user'] = true;

wfLoadExtension('MobileFrontend');
$wgMFDefaultSkinClass = 'SkinMinerva'; # TODO: Remove; @deprecated (unknown version)
$wgDefaultMobileSkin = 'minerva';

wfLoadExtension('Interwiki');
$wgSharedTables[] = 'interwiki';

# Centralised interlanguage links: one shared page_translations table maps a
# concept (keyed by its English title) to the page title in every language.
wfLoadExtension('CentralLangLinks');
$wgSharedTables[] = 'page_translations';
# Let any logged-in (account-approved) user manage translations via
# Special:Translations, not just sysops.
$wgGroupPermissions['user']['managetranslations'] = true;

# Shared English news/events boxes: every language wiki's Template:Events and
# Template:News are thin wrappers that transclude the English originals via the
# `hwen` interwiki prefix (points at the container's internal Apache, so the
# fetch bypasses the public Anubis interstitial). One source of truth = English.
$wgEnableScaryTranscluding = true;
$wgTranscludeCacheExpiry = 1800; // refresh the shared boxes at most every 30 min

# Shared infoboxes: a translated article does not carry its own infobox, it
# renders the English article's one (looked up through page_translations). Same
# idea as the news boxes above — the facts are edited on the English article and
# nowhere else — but the infobox templates only exist on the English wiki, so
# what crosses over is rendered HTML fetched from the internal Apache.
wfLoadExtension('SharedInfobox');

wfLoadExtension('AntiSpoof');
$wgSharedTables[] = 'spoofuser';

wfLoadExtension('UserMerge');
$wgGroupPermissions['bureaucrat']['usermerge'] = true;

wfLoadExtension('AkismetKlik');
$wgAKkey = $_ENV['MEDIAWIKI_AKISMET_KEY'];
$wgGroupPermissions['autopatrolled']['bypassakismet'] = true;
$wgGroupPermissions['sysop']['bypassakismet'] = true;
$wgGroupPermissions['bot']['bypassakismet'] = true;
$wgGroupPermissions['bureaucrat']['bypassakismet'] = true;

wfLoadExtension('SpamBlacklist');
$wgSpamBlacklistFiles = [
	"https://meta.wikimedia.org/w/index.php?title=Spam_blacklist&action=raw&sb_ver=1",
	"https://en.wikipedia.org/w/index.php?title=MediaWiki:Spam-blacklist&action=raw&sb_ver=1"
];

wfLoadExtension('TitleBlacklist');
$wgTitleBlacklistSources = [
	[
		'type' => 'localpage',
		'src' => 'MediaWiki:Titleblacklist',
	],
	[
		'type' => 'url',
		'src' => 'https://meta.wikimedia.org/w/index.php?title=Title_blacklist&action=raw',
	]
];

wfLoadExtension('TorBlock');
$wgGroupPermissions['user']['torunblocked'] = true; # Authenticated users can browse via Tor

## One-click newsletter unsubscribe (Special:Unsubscribe, RFC 8058). The shared
## HMAC secret must match the value given to the newsletter sender; it lives in
## .env (gitignored) and is injected into the container via env_file.
wfLoadExtension('Unsubscribe');
$wgUnsubscribeSecret = $_ENV['UNSUBSCRIBE_SECRET'] ?? '';

## Post a one-time "Thanks" message to a user's talk page when they make their
## 3rd edit (counted globally, since the user table is shared). The message is
## always posted on the English wiki, signed by Guaka and TillWenke.
wfLoadExtension('ThanksOnThirdEdit');

wfLoadExtension('ConfirmAccount');
$wgGroupPermissions['*']['createaccount'] = false;
$wgGroupPermissions['bureaucrat']['createaccount'] = true;

$wgConfirmAccountRequestFormItems = [
	'UserName' => ['enabled' => true],
	'RealName' => ['enabled' => false],
	'Biography' => ['enabled' => true, 'minWords' => 20],
	'AreasOfInterest' => ['enabled' => false],
	'CV' => ['enabled' => false],
	'Notes' => ['enabled' => false],
	'Links' => ['enabled' => false],
	'TermsOfService' => ['enabled' => false],
];

# Restrict ConfirmAccount usernames to lowercase a-z and digits only.
# Checks the raw typed name ($params['userName']) before MediaWiki canonicalises
# it (the first letter is still auto-capitalised on account creation).
# This is to prevent any downstream problems of handling usernames with odd symbols e.g. we saw some special characters for French accounts in the past.
$wgHooks['ConfirmAccount::checkRequest'][] = function ( $user, $params, &$message ) {
	$typedName = trim( $params['userName'] ?? '' );
	if ( !preg_match( '/^[a-z0-9]+$/', $typedName ) ) {
		$message = 'Username may only contain lowercase letters (a-z) and numbers (0-9), with no spaces or other characters.';
		return false;
	}
	return true;
};

# Surface a "Request account" link on the login form.
# Account creation is disabled above, so MediaWiki's core "create one" link is
# hidden, and the Minerva (mobile) skin strips ConfirmAccount's injected
# user-menu link for anonymous users (DefaultMainMenuBuilder::getPersonalToolsGroup
# keeps only 'login'/'login-private'). That left mobile visitors with no visible
# way to reach Special:RequestAccount. Adding it here renders it on the login form
# for every skin, mobile included.
$wgHooks['AuthChangeFormFields'][] = function ( $requests, $fieldInfo, &$formDescriptor, $action ) {
	if ( $action !== MediaWiki\Auth\AuthManager::ACTION_LOGIN ) {
		return true;
	}
	$href = SpecialPage::getTitleFor( 'RequestAccount' )->getLocalURL();
	$formDescriptor['requestAccountLink'] = [
		'type' => 'info',
		'raw' => true,
		'default' => MediaWiki\Html\Html::rawElement(
			'div',
			[ 'class' => 'mw-confirmaccount-requestaccount-link' ],
			MediaWiki\Html\Html::element(
				'a',
				[ 'href' => $href ],
				wfMessage( 'requestaccount-login' )->text()
			)
		),
		'weight' => 100,
	];
	return true;
};

## Group permissions
$wgGroupPermissions['sysop']['abusefilter-modify'] = true;
$wgGroupPermissions['*']['abusefilter-log-detail'] = true;
$wgGroupPermissions['*']['abusefilter-view'] = true;
$wgGroupPermissions['*']['abusefilter-log'] = true;
$wgGroupPermissions['sysop']['abusefilter-private'] = true;
$wgGroupPermissions['sysop']['abusefilter-modify-restricted'] = true;
$wgGroupPermissions['sysop']['abusefilter-revert'] = true;
$wgGroupPermissions['user']['edit'] = true;
$wgGroupPermissions['sysop']['edit'] = true;
$wgGroupPermissions['bot']['edit'] = true;

# Lock non-flagship wikis to sysop/bot edits only; en/de/fr remain fully open.
$hwLockedWikis = array_diff(array_keys($hwLanguages), ['en', 'de', 'fr']);
if (in_array($wikiID, $hwLockedWikis, true)) {
	$wgGroupPermissions['user']['edit'] = false;
}
$wgGroupPermissions['autopatrolled']['autopatrol'] = true;
$wgGroupPermissions['autopatrolled']['skipcaptcha'] = true;
$wgGroupPermissions['user']['skipcaptcha'] = true;
$wgGroupPermissions['*']['edit'] = false;
$wgGroupPermissions['bureaucrat']['skipcaptcha'] = true;
$wgGroupPermissions['sysop']['skipcaptcha'] = true;
$wgGroupPermissions['sysop']['interwiki'] = true;

// ConfirmAccount: only CheckUsers gets account approvals and notifications.
// The group is 'checkuser', singular — the name the CheckUser extension defines.
// It was spelled 'checkusers' here, which matches no group, so getAdminsToNotify()
// resolved to nobody and account requests silently notified no one.
$wgGroupPermissions['bureaucrat']['confirmaccount'] = true;
$wgGroupPermissions['bureaucrat']['confirmaccount-notify'] = false;
$wgGroupPermissions['sysop']['confirmaccount'] = true;
$wgGroupPermissions['sysop']['confirmaccount-notify'] = false;
$wgGroupPermissions['checkuser']['confirmaccount'] = true;
$wgGroupPermissions['checkuser']['confirmaccount-notify'] = true;

### OAuth configuration start ###
wfLoadExtension('OAuth');

$wgOAuthSecretKey = $_ENV['OAUTH_SECRET_KEY'] ?? '';

# Enable OAuth 2.0 (it is disabled by default)
$wgOAuth2EnabledGrantTypes = [
    "authorization_code",
    "refresh_token",
    "client_credentials"
];

# Minimal Permissions: Allow users to create their own "Consumers" (Apps)
$wgGroupPermissions['user']['mwoauthproposeconsumer'] = true;
$wgGroupPermissions['user']['mwoauthupdateownconsumer'] = true;
$wgGroupPermissions['sysop']['mwoauthmanageconsumer'] = true;

$wgOAuth2PrivateKey = "/var/www/html/oauth2.key";
$wgOAuth2PublicKey  = "/var/www/html/oauth2.pub";
# create oauth consumers on https://hitchwiki.org/en/Special:OAuthConsumerRegistration
### OAuth configuration end ###

## Additional configuration
$wgEmailConfirmToEdit = true;

# TODO: These might not be great settings:
$wgAllowUserCss = true; # I like it, but isn't it a slight security risk?
$wgRestrictDisplayTitle = false; # This makes it possible to have lowercase letters at the beginning of the title; not recommended by MW.
$wgBlockAllowsUTEdit = false; # Disallows blocked users from editing their own talk page; seems fine to me?
$wgAllowExternalImages = true; # Why would we allow images from other sources?
$wgAllowExternalImagesFrom = $wgServer; //'https://hitchwiki.org/'; # Wait, only our own source? Is this really correct? Seems related to languages.
$wgAutopromote["advanced"] = [APCOND_EDITCOUNT, 1]; # Promote users after one edit?
$wgAutoConfirmAge = 60 * 60 * 24 * 7; # (7 days) number of seconds account needs to have existed to autoconfirm
$wgAutoConfirmCount = 7; # Number of edits an account needs to have to autoconfirm

$wgFileExtensions = array_merge($wgFileExtensions, ['svg', 'pdf']);

# Disable creating users via API
# Currently we this won't work anyway due our captcha extension
# https://github.com/vedmaka/Mediawiki-reCaptcha/issues/4
$wgAPIModules['createaccount'] = 'ApiDisabled';
$wgAPIModules['tokens'] = 'ApiDisabled';

# DNS Blacklist
# TODO: Is disabled, should we enable it?
$wgEnableDnsBlacklist = false;
$wgDnsBlacklistUrls = ['xbl.spamhaus.org', 'dnsbl.tornevall.org', 'http.dnsbl.sorbs.net.'];

## Deprecated configuration, TODO: Remove when upgraded
$wgUseAjax = true;
$wgLocalInterwiki = $wgSitename;
$wgStyleDirectory = "$IP/skins";
$wgStylePath = "$wgScriptPath/skins"; # TODO: Will default to this in 1.4+

## Debug and development modes
$isDebug = isset($_ENV['DEBUG']) && (bool) $_ENV['DEBUG'];
$isDevelopment = isset($_ENV['DEVELOPMENT']) && (bool) $_ENV['DEVELOPMENT'];

if ($isDebug || $isDevelopment) {
	$wgShowExceptionDetails = true;
	$wgShowDebug = true;
	$wgDevelopmentWarnings = true;
	$wgDebugToolbar = true;
}

if ($isDevelopment) {
	# Replace with Mailpit or MailHog settings
	$wgSMTP = [
		'host' => 'localhost',
		'IDHost' => 'localhost',
		'port' => 1025,
		'auth' => false
	];
}

## Add a "Newsletter" subsection (rendered with the same heading style as the
## sibling "Internationalisation" subsection) containing the "Receive monthly
## newsletter" opt-in. Stores the choice in user_properties as
## `hw-newsletter-monthly`; sending the newsletter itself is handled separately.
## The subsection sits right above "Internationalisation" on Special:Preferences
## and is directly linkable via Special:Preferences#mw-prefsection-personal-newsletter.
$wgHooks['GetPreferences'][] = function ( $user, &$preferences ) {
	$newsletter = [
		'hw-newsletter-monthly' => [
			'type' => 'toggle',
			'label' => 'Receive monthly newsletter',
			'section' => 'personal/newsletter',
		],
	];
	$pos = false;
	foreach ( array_keys( $preferences ) as $i => $key ) {
		if ( ( $preferences[$key]['section'] ?? '' ) === 'personal/i18n' ) {
			$pos = $i;
			break;
		}
	}
	if ( $pos !== false ) {
		$preferences = array_slice( $preferences, 0, $pos, true )
			+ $newsletter
			+ array_slice( $preferences, $pos, null, true );
	} else {
		$preferences += $newsletter;
	}
	return true;
};

## Default the newsletter opt-in to on for new users (and anyone who hasn't
## explicitly toggled it). Stored value lives in user_properties as
## `hw-newsletter-monthly`; this only sets the fallback default.
$wgDefaultUserOptions['hw-newsletter-monthly'] = 1;

## Provide the heading text for the personal/newsletter subsection.
$wgHooks['MessagesPreLoad'][] = function ( $title, &$message, $code ) {
	if ( $title === 'Prefs-newsletter' ) {
		$message = 'Newsletter';
	}
	return true;
};

## Umami analytics (self-hosted). The tracker is served first-party from
## hitchwiki.org/1p/* by Caddy — a script loaded from the analytics hostname
## itself is dropped by a sizeable share of blocklists, from our own domain it
## is not. The tracker derives its collect URL from its own script src, so
## /1p/script.js posts to /1p/api/send with no extra config.
## Disabled by not setting MEDIAWIKI_UMAMI_WEBSITE_ID.
$hwUmamiWebsiteId = $_ENV['MEDIAWIKI_UMAMI_WEBSITE_ID'] ?? getenv('MEDIAWIKI_UMAMI_WEBSITE_ID') ?: '';
$hwUmamiScriptUrl = $_ENV['MEDIAWIKI_UMAMI_SCRIPT_URL'] ?? getenv('MEDIAWIKI_UMAMI_SCRIPT_URL') ?: 'https://hitchwiki.org/1p/script.js';
if ($hwUmamiWebsiteId !== '') {
	$wgHooks['BeforePageDisplay'][] = static function ( $out ) use ( $hwUmamiWebsiteId, $hwUmamiScriptUrl ) {
		$src = htmlspecialchars( $hwUmamiScriptUrl, ENT_QUOTES );
		$id = htmlspecialchars( $hwUmamiWebsiteId, ENT_QUOTES );
		$out->addHeadItem(
			'umami-analytics',
			"<script defer src=\"{$src}\" data-website-id=\"{$id}\"></script>"
		);
	};
}

## Load private settings if available
$privateFile = dirname(__FILE__) . '/PrivateSettings.php';
if (is_readable($privateFile)) {
	require $privateFile;
}

## Configure caching to invalidate on configuration changes
$configDate = gmdate('YmdHis', @filemtime(__FILE__));
$wgCacheEpoch = max($wgCacheEpoch, $configDate);
