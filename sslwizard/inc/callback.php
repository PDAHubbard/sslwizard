<?php
#
# AJAX-based SSL-Wizard
# Copyright (c) 2011 CertCenter AG
#
# Diese Software wird registrierten Partnern der CertCenter AG kostenfrei zur Verfügung gestellt.
# Die Software unterliegt jedoch keiner OpenSource-Lizenz, weshalb die unautorisierte Verbreitung
# nicht gestattet ist. Der Quelltext dieser Software unterliegt der Geheimhaltungsvereinbarung.
# Es gelten die Allgemeinen Geschäftsbedingungen der CertCenter	AG.
#

/*
	Methode dieser Klasse werden vom Wizard aufgerufen,
	um individuelle Authentifizierungen, Preise oder sonstige
	kundenspezifische Angaben in den Wizard einfliessen zu lassen.
*/
class CC_CALLBACK {

	private $error_message;

	/* Wird aufgerufen, sobald der Wizard initialisiert wird.
	   -
	   Hier kann z.B. der übergebene ProductCode oder eine Kundensitzung abgefangen
	   und schon vor Start des Wizards ausgewertet werden.
	   Ist ein Kunde nicht authentifiziert, wäre hier z.B. der beste Platz,
	   um den Kunden auf eine Login-Seite weiterzuleiten.
	*/
	public function wizard_on_initialize() {
	}


	/* Wird bei jedem AJAX-Request aufgerufen
	   -
	   POST-Anfragen werden ausschließlich durch den Wizard über die
	   AJAX-Funktionen ausgeführt. Hier könnte z.B. auch auf eine gültige
	   Sitzung geprüft werden.
	*/
	public function wizard_on_ajax_request() {
		
	}


	/* Übergibt den waehrend der Konfiguration über den
	   Wizard neu kalkulierten Netto-Preis. 
	   -
	   Bei Rueckgabe von "false" wird der Kunde per Popup benachrichtigt,
	   dass sein Guthaben/Limit nicht ausreicht und der "Weiter"-Button
	   blockiert.
	*/
	public function wizard_on_recaluclate($price=0.00) {

		/* Beispiel:

		if($price>500) {
			$this->error_message = "Die Gesamtkosten für das Zertifikat übersteigen das vorgegebene Limit. Bitte korrigieren Sie Ihre Angaben und wiederholen Sie den Vorgang.";
			return false;
		}

		*/

		return true;
	}
	

	/* Wird aufgerufen, wenn eine Bestellung erfolgreich
	   abgeschlossen wurde.
	*/
	public function wizard_on_order_succeed($report) {
		/*
			Hier ist der Platz für interne Abrechungs-Funktionen.
			Fügen Sie den Abrechnungposten in eine Datenbank ein oder
			lassen Sie sich eine E-Mail senden.
			
			mail('buchhaltung@provider.de', 'SSL-Wizard: Neue Bestellung', var_export($report,true));
		*/

		#
		# Ist CC_DB Klasse vorhanden, werden Basisdaten in die Datenbank geschrieben.
		# Diese werden dazu genutzt um im spaeteren Schritt, Statusupdates an den
		# Kunden weiterzuleiten.
		#
		
		if(class_exists("CC_DB")) {
			$db = new CC_DB;

			$s = @$report->payload->ORDER->san_hosts;
			$san_hosts = is_array($s) ? "'".implode(",", $s)."'" : "NULL";

			# Falls gewusncht, setzen Sie hier dynamisch die E-Mail-Adresse des Kunden ein,
			# der das Zertifikat nach Ausstellung erhalten soll. Wichtig: Der Versand per E-Mail
			# funktioniert nur, wenn Sie statuscheck.php regelmaessig per Cronjob laufen lassen:
			# (Standard: Technischer Ansprechpartner empfaengt Zertifikat)

			$EmailRecipient = $report->payload->ORDER->contact_tech->email;

			# BEISPIEL: $EmailRecipient = $report->payload->ORDER->contact_admin->email;
			# BEISPIEL: $EmailRecipient = $_SESSION['xy']['EMAIL'];
			  
			# Hier kann eine Kundennummer uebergeben werden (z.B. aus Sitzungsdaten)
			# BEISPIEL: $KUNDE_NR = "'D99999'";
			$KUNDE_NR = "NULL";

			##########

			$sql = "
INSERT INTO `ZERTIFIKATE` (
	`CERTCENTER_ORDERID`,
	`KUNDE_NR`,
	`PRODUCT_CODE`,
	`VALIDITY_PERIOD`,
	`RENEW`,
	`CUPGRADE`,
	`LICENSES`,
	`PLATFORM`,
	`SAN_HOSTS`,
	`EMAIL_RECIPIENT`
) VALUES (
	".$report->CertCenterOrderID.",
	".$KUNDE_NR.",
	'".$report->payload->PRODUCT_CODE."',
	".$report->payload->ORDER->validity_period.",
	".(($report->payload->ORDER->renew=='false')?0:1).",
	".(($report->payload->ORDER->cupgrade=='false')?0:1).",
	".$report->payload->ORDER->licenses.",
	'".$report->payload->ORDER->platform."',
	".$san_hosts.",
	".($EmailRecipient==''?'NULL':"'".$EmailRecipient."'")."
)
			";
			mysql_query($sql, $db->db_link) or die(mysql_error($db->db_link));

			##########

		}
	}


	/* Wird aufgerufen, wenn eine Bestellung aufgrund eines
	   Fehlers fehlgeschlagen ist.
	*/
	public function wizard_on_order_failed($report) {
		/*
			Hier ist der Platz für die Weiterleitung von Bestellinformationen
			bei Abbruch aufgrund von Fehlern. Informieren Sie Ihren internen
			Administrator:
			
			mail('admin@provider.de', 'SSL-Wizard: Abgebrochene Bestellung', var_export($report,true));
		*/
	}



	# Helper-Methoden

	public function _error_message() {
		return ($this->error_message!=''?$this->error_message:
			"Unbekanntes Problem - bitte wenden Sie sich an den Kundenservice");
	}
}


?>
