<?php
#
# AJAX-based SSL-Wizard
# Copyright (c) 2011 CertCenter AG
#
# Diese Software wird registrierten Partnern der CertCenter AG kostenfrei zur Verfügung gestellt.
# Die Software unterliegt jedoch keiner OpenSource-Lizenz, weshalb die unautorisierte Verbreitung
# nicht gestattet ist. Der Quelltext dieser Software unterliegt der Geheimhaltungsvereinbarung.
#

class CC_DB {
	var $db_link=false;
	function __construct() {
		$this->db_link = mysql_connect(DB_HOST, DB_USER, DB_PASS);
		if(!$this->db_link) die("Datenbankverbindung fehlgeschlagen");
		mysql_select_db(DB_DATABASE, $this->db_link);
		mysql_query("SET NAMES 'utf8'", $this->db_link);
		mysql_query("SET CHARACTER SET utf8", $this->db_link);
	}
}

?>
