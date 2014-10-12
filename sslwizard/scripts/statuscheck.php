#!/bin/env php
<?php
#
# AJAX-based SSL-Wizard
# Copyright (c) 2011 CertCenter AG
#
# Diese Software wird registrierten Partnern der CertCenter AG kostenfrei zur Verfügung gestellt.
# Die Software unterliegt jedoch keiner OpenSource-Lizenz, weshalb die unautorisierte Verbreitung
# nicht gestattet ist. Der Quelltext dieser Software unterliegt der Geheimhaltungsvereinbarung.
#

require_once '../inc/conf.inc.php';
require_once '../inc/db.inc.php';
require_once '../inc/api.inc.php';

class CC_STATUSCHECK extends CC_API {

	var $db=false;

	function __construct() {
		parent::__construct();
		$this->db = new CC_DB();
	}
	
	function GetLocalCertInfo($CertCenterOrderID) {
		$rs = mysql_query("SELECT * FROM `ZERTIFIKATE` WHERE `CERTCENTER_ORDERID`=".$CertCenterOrderID, $this->db->db_link);
		if(mysql_num_rows($rs)!=1) return false;
		return mysql_fetch_object($rs);
	}

	function SetCertStatus($CertCenterOrderID, $status) {
		mysql_query("UPDATE `ZERTIFIKATE` SET `STATUS`='$status' WHERE `STATUS`!='CANCELLED' AND `CERTCENTER_ORDERID`=".$CertCenterOrderID, $this->db->db_link);
		if(mysql_affected_rows($this->db->db_link)!=1) return false;
		return true;
	}
	
	function SetCertStartEnd($CertCenterOrderID, $StartDate, $EndDate) {
		mysql_query("UPDATE `ZERTIFIKATE` SET `START_DATE`='$StartDate', `END_DATE`='$EndDate' WHERE `CERTCENTER_ORDERID`=".$CertCenterOrderID, $this->db->db_link);
		if(mysql_affected_rows($this->db->db_link)!=1) return false;
		return true;
	}

	function compute() {
		if(!file_exists(".sslwizard.checkts"))
			touch(".sslwizard.checkts");
		$checkts = filemtime(".sslwizard.checkts");
		touch(".sslwizard.checkts");
		#touch -d "2011-04-04 16:30:00" .sslwizard.checkts
		$res = $this->GetModifiedOrders(date('c', $checkts), date('c'));

		if(@isset($res->OrderDetails->OrderDetail)) {

			if(is_object($res->OrderDetails->OrderDetail)) {
				$tmp = $res->OrderDetails->OrderDetail;
				$res->OrderDetails->OrderDetail=array();
				$res->OrderDetails->OrderDetail[0]=$tmp;
				unset($tmp);
			}
			$details = $res->OrderDetails->OrderDetail;
		
			for($i=0;$i<count($details);$i++) {
				$detail = $details[$i];
				$CertCenterOrderID	= $detail->OrderInfo->CertCenterOrderID;
				$PartnerOrderID		= $detail->OrderInfo->PartnerOrderID;
				$CertificateStatus 	= $detail->CertificateInfo->CertificateStatus;
				$CommonName 		= $detail->CertificateInfo->CommonName;
				$LocalCertInfo 		= $this->GetLocalCertInfo($CertCenterOrderID);
				if(!$LocalCertInfo) {
					# Nicht in lokaler DB vorhanden
					echo "Zertifikat ($CertCenterOrderID) nicht in lokaler DB.\n";
				} else {
					if($LocalCertInfo->STATUS!=$CertificateStatus) {
						# Zertifikatsstatus wurde veraendet
						#print_r($LocalCertInfo);
						$this->SetCertStatus($CertCenterOrderID, $CertificateStatus);
						# Aktionen, die bei Statusaenderungen ausgefuehrt werden
						switch($CertificateStatus) {

							case 'CANCELLED':	# Der Zertifikatsantrag wurde abgebrochen

								if($LocalCertInfo->EMAIL_RECIPIENT!='') {
									$subject = "Ihr Zertifikatsantrag wurde abgebrochen";
									$text = "Sehr geehrter Kunde,

soeben wurde uns von der CA mitgeteilt, dass das SSL-Zertifikat für

	$CommonName

abgebrochen wurde.

Für Rückfragen stehen wir Ihnen gerne zur Verfügung.

";
									# Bitte nutzen Sie hier möglichst eine erweiterte Mail-Funktion,
									# die ein entsprechendes Encoding unterstützt (z.B. UTF-8). Die
									# PHP-Standard-Funktion ist für den Einsatz in Produktivsystemen
									# nur bedingt empfehlenswert.

									mail($LocalCertInfo->EMAIL_RECIPIENT, $subject, wordwrap(trim($text), 75).EMAIL_SIGNATURE, EMAIL_DEFAULT_HEADERS);
								}
								break;

							case 'COMPLETE':	# Das Zertifikat wurde ausgestellt
							
								$StartDate = strtotime($detail->CertificateInfo->StartDate);
								$EndDate = strtotime($detail->CertificateInfo->EndDate);
								$this->SetCertStartEnd($CertCenterOrderID, $StartDate, $EndDate);

								$SERVERCERT = $detail->Fulfillment->ServerCertificate;
								$INTERMEDIATES = "";
								if(isset($detail->Fulfillment->CACertificates->CACertificate)) {
									if(is_array($detail->Fulfillment->CACertificates->CACertificate)) {
										# Multiples IM
										foreach($detail->Fulfillment->CACertificates->CACertificate as $x)
											if($x->Type=='INTERMEDIATE')
												$INTERMEDIATES.=$x->CACert;
									} else {
										# Einfaches IM
										if($detail->Fulfillment->CACertificates->CACertificate->Type=='INTERMEDIATE') {
											$INTERMEDIATES.=$detail->Fulfillment->CACertificates->CACertificate->CACert;
										}
									}
								}

								if($LocalCertInfo->EMAIL_RECIPIENT!='') {

									$subject = "Ihr Zertifikat wurde erfolgreich ausgestellt";
									$text = "Sehr geehrter Kunde,

gerne übermitteln wir Ihnen das fertige SSL-Zertifikat für Ihren Host ".$CommonName.":

$SERVERCERT

";

									if($INTERMEDIATES!='') {
										# Intermediate-Zertifikate anhaengen
										$text .= "

Bitte beachten Sie, dass außer der Installation des SSL-Zertifikats die einmalige Installation des folgenden Zwischenzertifikats notwendig ist:

$INTERMEDIATES


";
									}
									
									if( isset($detail->Fulfillment->IconScript) ) {
										# Script fuer Site-Seal
										$text .= "

Folgenden Code für das Sicherheits-Siegel können Sie ab sofort auf Ihrer Website platzieren:

".$detail->Fulfillment->IconScript."


";
									}

									# Bitte nutzen Sie hier möglichst eine erweiterte Mail-Funktion,
									# die ein entsprechendes Encoding unterstützt (z.B. UTF-8). Die
									# PHP-Standard-Funktion ist für den Einsatz in Produktivsystemen
									# nur bedingt empfehlenswert.

									mail($LocalCertInfo->EMAIL_RECIPIENT, $subject, wordwrap(trim($text), 75).EMAIL_SIGNATURE, EMAIL_DEFAULT_HEADERS);
								}

								break;
							case 'REVOKED':		# Das Zertifikat wurde zurueckgezogen
								break;
						}
					}
				}
			}
		}
	}
}

if(!CC_WIZARD_DEVEL)
	if(md5(EMAIL_SIGNATURE)=='c0884cc1cbd7a526ef6d9b394dc04e31')
		die("FEHLER: Bitte passen Sie Ihre E-Mail-Signatur in der conf.inc.php an!\n");

$instance = new CC_STATUSCHECK;
$instance->compute();

?>
