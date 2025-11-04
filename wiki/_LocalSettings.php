<?php

# THIS FILE CURRENTLY CONTAINS THE ORIGINAL SETTINGS REMAINING AFTER MOVING ALL POSSIBLE ONES OVER
# I'VE REMOVED DEPRECATED SETTINGS OR THOSE EQUIVALENT TO DEFAULT SETTINGS IN THE NEW LOCALSETTINGS.PHP
# EVERYTHING THAT STILL EXISTS IN 1.32 BUT WILL BE DEPRECATED IN THE FUTURE IS MARKED AS SUCH

$path = array($IP, "$IP/includes", "$IP/languages");
$path = array("$IP/php-openid", $IP, "$IP/includes", "$IP/languages");  // for OpenID library
set_include_path( implode( PATH_SEPARATOR, $path ) . PATH_SEPARATOR . get_include_path());

$wgRepositoryBaseUrl = "https://hitchwiki.org/en/Image:";

$wgImportSources = array('hitchhiking');

$wgRelatedSitesPrefixes     = array('wikipedia', 'digi', 'trash', 'nomad');

/*
 * Locking out spambots.
 * This might also affect some users, but it's still better than disabling registering completely
 */
$browserBlacklist = array(
    'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.10) Gecko/20100914 Firefox/3.6.10',
    'Mozilla/5.0 (Windows; U; Windows NT 5.2; en-US)'
);