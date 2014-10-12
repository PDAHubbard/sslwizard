<?php
#
# AJAX-based SSL-Wizard
# Copyright (c) 2011 CertCenter AG
#
# Diese Software wird registrierten Partnern der CertCenter AG kostenfrei zur Verfügung gestellt.
# Die Software unterliegt jedoch keiner OpenSource-Lizenz, weshalb die unautorisierte Verbreitung
# nicht gestattet ist. Der Quelltext dieser Software unterliegt der Geheimhaltungsvereinbarung.
#


define('DB_HOST', 'localhost');
define('DB_DATABASE', 'SSLWIZARD');
define('DB_TABLE', 'ZERTIFIKATE');

define('DB_USER', 'sslwizard');
define('DB_PASS', 'sslwizard_password');

define('CC_API_USERNAME', '#API_USERNAME#');
define('CC_API_PASSWORD', '#API_PASSWORD#');

define('CC_WIZARD_VERSION', 1022);
define('CC_WIZARD_DEVEL', false);

date_default_timezone_set("Europe/Berlin");

define('EMAIL_SIGNATURE', 		"Ihr Rundum-ISP\nMusterstrasse 8\n99999 Musterstadt");
define('EMAIL_DEFAULT_HEADERS',	"Content-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit");

###

/* Prüfe, ob benötigte PHP-Funktionen zur Verfügung stehen.
*/

/* json (PHP 5 >= 5.2.0, PECL json >= 1.2.0) */
if(!function_exists("json_decode"))
	die("Der SSL-Wizard benötigt die PHP-Funktion 'json_decode()' "
		."(PHP 5 >= 5.2.0, PECL json >= 1.2.0)");

/* PHP-SOAP wird benoetigt (http://www.php.net/manual/de/book.soap.php) */
if(!class_exists("SoapClient"))
	die("Zur Kommunikation mit dem Server benötigt der SSL-Wizard das PHP-SOAP Modul "
		."(<a href='http://www.php.net/manual/de/book.soap.php'>http://www.php.net/manual/de/book.soap.php</a>)");

###

if(CC_WIZARD_DEVEL) {
	error_reporting(-1);
	ini_set("soap.wsdl_cache_enabled", 0);
} else {
	error_reporting(0);
	ini_set("soap.wsdl_cache_enabled", 1);
}

?>
