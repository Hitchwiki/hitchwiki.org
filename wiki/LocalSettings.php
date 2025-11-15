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
$wgEnableUploads = true;
$wgUseImageMagick = true;
$wgImageMagickConvertCommand = "/usr/bin/convert";

# Upload Paths
$wgUploadPath = "$wgScriptPath/images/$wikiID";
$wgUploadDirectory = "$IP/images/$wikiID";

# Base Repository
$wgRepositoryBaseUrl = "$wgServer/$defaultLang/Image:";

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

## Enabled extensions and configuration.
wfLoadExtension('DismissableSiteNotice');
wfLoadExtension('ExternalData');
wfLoadExtension('ParserFunctions');
wfLoadExtension('GeoCrumbs');
wfLoadExtension('CheckUser');
wfLoadExtension('Nuke');

wfLoadExtension('ConfirmEdit');
// TODO: Enable reCAPTCHA once the keys are set in environment variables
// wfLoadExtension('ConfirmEdit/ReCaptchaNoCaptcha');
// $wgCaptchaClass = 'ReCaptchaNoCaptcha';
// $wgReCaptchaSiteKey = $_ENV['RECAPTCHA_SITE_KEY'];
// $wgReCaptchaSecretKey = $_ENV['RECAPTCHA_SECRET_KEY'];
// $wgReCaptchaSendRemoteIP = true;
// $wgCaptchaTriggers['edit'] = true;
// $wgCaptchaTriggers['create'] = true;
// $wgCaptchaTriggers['addurl'] = true;
// $wgCaptchaTriggers['createaccount'] = true;
// $wgCaptchaTriggers['badlogin'] = true;
// $wgCaptchaTriggersOnNamespace[NS_TALK]['edit'] = true;
// $wgCaptchaTriggersOnNamespace[NS_TALK]['create'] = true;
// $wgCaptchaTriggersOnNamespace[NS_TALK]['addurl'] = true;

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
$wgGroupPermissions['autopatrolled']['autopatrol'] = true;
$wgGroupPermissions['autopatrolled']['skipcaptcha'] = true;
$wgGroupPermissions['*']['edit'] = false;
$wgGroupPermissions['bureaucrat']['skipcaptcha'] = true;
$wgGroupPermissions['sysop']['skipcaptcha'] = true;
$wgGroupPermissions['sysop']['interwiki'] = true;

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

## Load private settings if available
$privateFile = dirname(__FILE__) . '/PrivateSettings.php';
if (is_readable($privateFile)) {
	require $privateFile;
}

## Configure caching to invalidate on configuration changes
$configDate = gmdate('YmdHis', @filemtime(__FILE__));
$wgCacheEpoch = max($wgCacheEpoch, $configDate);
