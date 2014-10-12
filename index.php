<?php
# SSL-Wizard - Diese Zeilen müssen ganz oben in Ihren
# PHP-Quelltext (möglichst Zeile 1 bis Zeile 3).
require_once 'sslwizard/library.php';


# Produktkennung
$pcode = isset($_GET['pcode']) ? $_GET['pcode'] : 'QuickSSLPremium';
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8" />
	<title>Ihre Firma GmbH - SSL Wizard - Beispiel</title>

	<!-- SSL-Wizard START - Die folgenden Zeilen
	     in die <head></head>-Sektion einsetzen -->
	<script type="text/javascript" src="sslwizard/prototype.js"></script>
	<script type="text/javascript" src="sslwizard/effects.js"></script>
	<script type="text/javascript" src="sslwizard/library.js"></script>
	<link rel="stylesheet" href="sslwizard/main.css" type="text/css" />
	<!-- SSL-Wizard ENDE -->

</head>
<body style='background:#efefef;'>

	<div style='width:750px;padding:20px;margin:auto;'>
		<!-- SSL-Wizard START - Das folgende DIV an der
			 Stelle platzieren, an der der Wizard geladen werden soll -->
		<div id='cc_ssl_wizard' product='<?php echo $pcode?>' tax='19.00'></div>
		<!-- SSL-Wizard ENDE -->
	</div>

</body>
</html>
