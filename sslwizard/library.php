<?php
#
# AJAX-based SSL-Wizard
# Copyright (c) 2011 CertCenter AG
#
# Diese Software wird registrierten Partnern der CertCenter AG kostenfrei zur Verfügung gestellt.
# Die Software unterliegt jedoch keiner OpenSource-Lizenz, weshalb die unautorisierte Verbreitung
# nicht gestattet ist. Der Quelltext dieser Software unterliegt der Geheimhaltungsvereinbarung.
#

setlocale(LC_MONETARY,'de_DE');

require_once 'inc/conf.inc.php';
require_once 'inc/api.inc.php';
require_once 'inc/db.inc.php';
require_once 'inc/callback.php';

class CC_INSTANCE extends CC_API {

	private $payload_in = false;
	private $payload_out = array();
	private $wizard_step = 0;
	private $api = false;
	private $steps = false;
	private $tax = 0.00;
	private $callback = false;

	/* Konstruktor
	*/
	function __construct() {

		$this->callback = new CC_CALLBACK();
	
		if($_SERVER['REQUEST_METHOD']=='POST') {
			$this->callback->wizard_on_ajax_request();
			$this->post_handler();
			exit;
		} else {
			$this->api = new CC_API();
			$this->api->GetLimit();
			if($this->api->Limit->Used>=$this->api->Limit->Limit)
				die("Der SSL-Wizard konnte nicht geladen werden [limits]");
			$this->callback->wizard_on_initialize();
		}
	}
	
	/* Sendet Signatur
	*/
	private function send_signature() {
		echo "--- BEGIN AJAX BASED SSL-WIZARD ---\n";
	}

	/* Verarbeitet POST-Requests
	*/
	public function post_handler() {
		
		$this->payload_in = json_decode(stripslashes($_POST['PAYLOAD']));

		if(isset($this->payload_in->TAX))
			$this->tax = $this->payload_in->TAX;
			if($this->tax>0.00) 
				$this->tax = $this->tax/100+1;
		else
			$this->tax = 0.00;

		switch($this->payload_in->ACTION) {

			case 'load_step':

				$this->api = new CC_API();
				$this->wizard_step = $this->payload_in->STEP;

				if(!$this->api->GetProductDetails($this->payload_in->PRODUCT_CODE)) {
					$this->payload_out = Array(
						'STATUS' => 'failure',
						'ERROR_MSG' => 'Die übergebene Produktkennung ist ungültig ('.$this->payload_in->PRODUCT_CODE.').'
					);
				} else {
					$this->payload_out = Array(
						'STATUS' => 'success',
						'HTML' => $this->wizard_load(),
						'STEPS' => count($this->steps)
					);
				}

				break;
				
			case 'request_quote':

				$this->api = new CC_API();
				if(!$this->api->GetWizardQuote(
									$this->payload_in->PRODUCT_CODE,
									$this->payload_in->VALIDITY_PERIOD,
									$this->payload_in->SERVER_COUNT,
									$this->payload_in->SAN_COUNT)) {
					if($this->api->WizardQuote==-1) {
						$this->payload_out = Array(
							'STATUS' => 'failure_product',
							'ERROR_MSG' => '<div style="color:red;padding:3px;">Das Produkt '.$this->payload_in->PRODUCT_CODE.' steht nicht zur Verfügung.</div>'
						);
					} else {
						$this->payload_out = Array(
							'STATUS' => 'failure',
							'ERROR_MSG' => 'Die übergebene Produktkennung ist ungültig ('.$this->payload_in->PRODUCT_CODE.').'
						);
					}
				} else {
					$quote = $this->api->WizardQuote;
					$mwst_info = " <span style='color:gray;font-size:0.9em;'>(exkl. MwSt.)</span>";
					$ret = $this->callback->wizard_on_recaluclate($quote);
					if($this->tax!=0.00) {
						$quote = round($quote*$this->tax,2);
						$mwst_info = " <span style='color:gray;font-size:0.9em;'>(inkl. ".(($this->tax-1)*100)."% MwSt.)</span>";
					}
					$CC_LOCALE_INFO = localeconv();

					if($ret==false) {
						$this->payload_out = Array(
							'STATUS' => 'failure_callback',
							'ERROR_MSG' => $this->callback->_error_message(),
							'PRICE' => $quote,
							'PRICE_STR' => number_format($quote, 2, $CC_LOCALE_INFO['mon_decimal_point'], $CC_LOCALE_INFO['mon_thousands_sep'])." &euro;".$mwst_info
						);
					}
					else {
						$this->payload_out = Array(
							'STATUS' => 'success',
							'PRICE' => $quote,
							'PRICE_STR' => number_format($quote, 2, $CC_LOCALE_INFO['mon_decimal_point'], $CC_LOCALE_INFO['mon_thousands_sep'])." &euro;".$mwst_info
						);
					}
				}
				break;
				
			case 'validate_csr':

				$this->api = new CC_API();
				if(!$this->api->ValidateCSR(stripslashes($_POST['CSR']))) {
					$this->payload_out = Array(
						'STATUS' => 'failure',
						'ERROR_MSG' => 'Der übermittelte CSR ist ungültig.',
					);
				} else {
					$csrdata = $this->api->ParsedCSR;
					if($csrdata->KeyLength<2048) {
						$this->payload_out = Array(
							'STATUS' => 'failure',
							'ERROR_MSG' => 'Das übermittelte CSR ist ungültig. Bitte erzeugen Sie ein neues CSR mit einer Schlüssellänge von 2048-bit',
						);
					} else {
						$html = "
							<table style='border:0px;width:90%;' cellpadding='2' cellspacing='2'>
								<tr><td style='font-weight:bold;'>Common Name:</td><td>".$csrdata->DomainName."</td></tr>
								<tr><td style='font-weight:bold;'>Organisation:</td><td>".$csrdata->Organization."</td></tr>
								<tr><td style='font-weight:bold;'>Abteilung:</td><td>".$csrdata->OrganizationUnit."</td></tr>
								<tr><td style='font-weight:bold;'>Region:</td><td>".$csrdata->State."</td></tr>
								<tr><td style='font-weight:bold;'>Land:</td><td>".$csrdata->Country."</td></tr>
								<tr><td style='font-weight:bold;'>E-Mail Adresse:</td><td>".$csrdata->Email."</td></tr>
								<tr><td style='font-weight:bold;'>Schlüssellänge:</td><td>".$csrdata->KeyLength."-bit</td></tr>
							</table>
						";
	
						$_approvers = $this->api->GetApproverList($this->payload_in->PRODUCT_CODE, $csrdata->DomainName);
						$approvers = "";
						if($_approvers!=false) {
							$i=0;
							foreach($_approvers as $ap) {
								$approvers .= "<input type='radio' name='approver_email' id='approver_email_id".$i."' class='rg_approver_email' value='".$ap->ApproverEmail."'".($i==0?' checked="checked"':'')." /> <label for='approver_email_id".$i."' style='cursor:pointer;'>".$ap->ApproverEmail."</label><br />";
								$i++;
							}
						}
		
						$this->payload_out = Array(
							'STATUS' => 'success',
							'CSRDATA' => $csrdata,
							'HTML' => $html,
							'APPROVERS' => $approvers,
						);
					}
				}

				break;
			
			/*
				Alle im Wizard gesammelten Daten werden hier an die Action "order" uebermittelt
			 	und als Bestellung zum CertCenter API gesendet. Das Resultat wird ausgwertet und
			 	dem Benutzer des Wizards dargestellt.
			 	-
			 	Hier werden die Callback-Funktionen "wizard_on_order_succeed" oder
			 	"wizard_on_order_failed" je nach Bedarf aufgerufen.
			*/
			case 'order':

				$report = (object)null;
				$report->timestamp = @mktime();
				$report->payload = $this->payload_in;

				$this->api = new CC_API();
				$CertCenterOrderID = $this->api->Order($this->payload_in->ORDER, $this->payload_in->PRODUCT_CODE);

				if($CertCenterOrderID!=false) {
					$report->CertCenterOrderID=$CertCenterOrderID;
					$ret = $this->callback->wizard_on_order_succeed($report);
					$this->payload_out = Array(
						'STATUS' => 'success',
						'ORDER_ID' => $CertCenterOrderID,
						'HTML' => "<strong>Herzlichen Glückwunsch!</strong><p>Die Bestellung wurde erfolgreich übermittelt.<br/><strong>Bitte notieren Sie sich Ihre Bestellnummer:</strong> ".$CertCenterOrderID."</p>",
					);
				} else {
					$report->errors = $this->api->errors_raw;
					$ret = $this->callback->wizard_on_order_failed($report);
					$this->payload_out = Array(
						'STATUS' => 'failure',
						'HTML' => "<strong>Es sind Fehler aufgetreten!</strong><p>Bei der Übermittlung sind Fehler aufgetreten. Bitte gehen Sie zurück (über &quot;&laquo; Vorheriger Schritt&quot;) und korrigieren Sie Ihre Angaben.</p><div style='font-size:0.9em;'>".$this->api->errors."</div>",
					);
				}

				break;

			default:
				$this->payload_out = Array(
					'STATUS' => 'failure',
					'ERROR_MSG' => 'Aktion unbekannt ('.$this->payload_in->ACTION.')'
				);
				break;
		}

		$this->send_signature();
		print json_encode($this->payload_out);
		return true;
	}

	private function _html_construct() {

		$this->steps = array(
			'STEP_COMMON'=>'Allgemeines',
			'STEP_CSR'=>'Zertifikatsantrag',
			'STEP_SAN'=>'SAN-Hosts',
			'STEP_CONTACTS'=>'Ansprechpartner',
			'STEP_DONE'=>'Auftrag übermitteln'
		);

		/* Erlaubt das Produkt keine SANs, so ist der Schritt STEP_SAN nicht erforderlich
		*/
		if(!in_array("SAN",$this->api->ProductDetails->Features))
			unset($this->steps['STEP_SAN']);

		$i=0;
		$base_construct = "<div id='cc_top_navigation'>";
		$steps = $this->steps;
		foreach($steps as $step_alias => $step_title) {
			$y=$i+1;
			if($i>0) $base_construct .= "<div class='cc_nav_div'></div>";
			$base_construct .= "<div alias='".$step_alias."' class='cc_nav_item".($this->payload_in->STEP==$i+1?' cc_nav_item_active':'')
							." cc_nav_item_alias_$step_alias' id='cc_nav_item_step$y'>$y) ".$step_title."</div>";
			$i+=1;
		}
		unset($steps);

		return $base_construct.= "<div class='cc_cb'></div></div>";
	}
	
	private function _SANFeatureMap($feature) {
		$SANFeatureMap = array(
			'dotLocals' =>			'.local Domains zulässig',
			'withoutPeriods' =>		'SAN-Hosts ohne Punkte zulässig, z.B. "server1"',
			'privateIP' =>			'private IP-Adressen (RFC 1597) zulässig',
			'externalDomains' =>	'SAN-Hosts unterschiedlicher Domains zulässig',
			'equalDomains' => 		'SAN-Hosts nur mit identischer Domain zulässig',
			'HostOnlyIncluded' =>	'https://domain.tld bei Bestellung für www.domain.tld (oder *.domain.tld) bereits enthalten'
		);
		if(array_key_exists($feature, $SANFeatureMap))
			return $SANFeatureMap[$feature];
		return $feature;
	}
	
	private function content_load($step) {
		$out = '';
		switch($step) {
			case 'STEP_COMMON':

				$this->api->GetProductDetails($this->payload_in->PRODUCT_CODE);
				$this->api->GetWizardInfos($this->payload_in->PRODUCT_CODE);
				$rows = "";
				$CC_LOCALE_INFO = localeconv();

				for($i=0;$i<sizeof($this->api->WizardInfos->MultiYearPricing);$i++) {
					$y=$i+1;
					$jahr = "Jahr";
					if($y>1) $jahr .= "e";
					$checked = "";
					$preis = $this->api->WizardInfos->MultiYearPricing[$i];
					$vorteil = $this->api->WizardInfos->MultiYearPricing[0];
					if($this->tax>0.00) {
						$preis *= $this->tax;
						$vorteil *= $this->tax;
					}
					$vorteil = ($vorteil*$y)-$preis;

					$rows .= "
						<tr>
							<td style='width:10px;'><input type='radio' class='rg_laufzeit' name='laufzeit' id='laufzeit_$y' onclick='cc.calculate_on_update()' value='$y'$checked /></td>
							<td style='width:300px;font-weight:bold;'><label style='cursor:pointer;' for='laufzeit_$y'>$y $jahr Gültigkeit für insgesamt ".number_format($preis,2,$CC_LOCALE_INFO['mon_decimal_point'],$CC_LOCALE_INFO['mon_thousands_sep'])." &euro;</label></td>
							<td>".($y>1?("<div style='color:green;'>".number_format($vorteil,2,$CC_LOCALE_INFO['mon_decimal_point'],$CC_LOCALE_INFO['mon_thousands_sep'])." &euro; Preisvorteil</div>"):'')."</td>
						</tr>
					";
				}

				$out .= "
					<div class='cc_step_title'>Gültigkeitsdauer</div>
					<div class='cc_step_content'>
						Die Gültigkeitsdauer ist die Zeitspanne, in der das neue Zertifikat gültig ist.
						Zum Ende dieses Zeitraumes muss das Zertifikat erneuert werden, damit die Sicherheit
						auch weiterhin gewährleistet werden kann und das Seiten-Siegel weiterhin zur
						Verfügung steht. 

						<table class='cc_step_content' cellspacing='0' cellpadding='6'>
							$rows
						</table>
					</div>
				";

				# Verlaengerungen sind nur bei Zertifikaten in folgendem Array moeglich.
				# 
				#
				if(in_array($this->api->ProductDetails->ProductCode, 
						array('TrueBusinessID','TrueBusinessIDWildcard','QuickSSLPremium','RapidSSL','RapidSSLWildcard',
						'SecureSite','SecureSitePro','SSL123','SGCSuperCerts','SSLWebServer','SSLWebServerWildcard'))) {

					$out .= "
						<div class='cc_step_title'>Verlängerung</div>
						<div class='cc_step_content'>
							Falls Sie für den gleichen Hostnamen über ein derzeit noch gültiges SSL-Zertifikat
							des gleichen Typs (<span class='highlight_productname'>".$this->api->ProductDetails->ProductName."</span>) verfügen, können Sie dieses Verlängern.
							Die Verlängerung kann bereits bis zu 90 Tage vor dem Ablaufdatum des alten Zertifikats
							erfolgen. In diesem Fall erhalten Sie die Restlaufzeit des alten Zertifikats auf das
							neue Zertifikat angerechnet. 
	
							<form>
								<table class='cc_step_content' cellspacing='0' cellpadding='6'>
									<tr>
										<td>
											<input type='radio' name='verlaengerung' class='rg_verlaengerung' value='1' id='verlaengerung_ja' onclick='$(\"cc_content_konkurrenzwechsel\").hide(); $(\"konkurrenzwechsel_nein\").checked=true;' />
											<label for='verlaengerung_ja' style='font-weight:bold;cursor:pointer;'>Ja, es existiert ein derzeit noch gültiges <span class='highlight_productname'>".$this->api->ProductDetails->ProductName."</span>-Zertifikat</label><br />
										</td>
									</tr>
									<tr>
										<td>
											<input type='radio' name='verlaengerung' class='rg_verlaengerung' checked='checked' value='0' id='verlaengerung_nein' onclick='$(\"cc_content_konkurrenzwechsel\").hide(); $(\"konkurrenzwechsel_nein\").checked=true;' />
											<label for='verlaengerung_nein' style='font-weight:bold;cursor:pointer;'>Nein, es existiert kein derzeit noch gültiges <span class='highlight_productname'>".$this->api->ProductDetails->ProductName."</span>-Zertifikat</label><br />
										</td>
									</tr>
								</table>
							</form>
	
						</div>
					";
				}

				$out .= "
					<div id='cc_content_konkurrenzwechsel' style='display:none;'>
					<div class='cc_step_title'>Wechsel von Konkurrenzprodukt</div>
					<div class='cc_step_content'>
						Sie verfügen über ein derzeit noch gültiges SSL-Zertifikat einer anderen Marke (z.B. GlobalSign oder Comodo CA)?
						Dann wird Ihnen die Restlaufzeit Ihres alten SSL-Zertifikats von bis zu 12 Monaten angerechnet,
						insofern VeriSign den Aussteller des bisherigen Zertifikats als Konkurrenzprodukt anerkennt. 
						<form>
							<table class='cc_step_content' cellspacing='0' cellpadding='6'>
								<tr>
									<td>
										<input type='radio' name='konkurrenzwechsel' class='rg_konkurrenzwechsel' value='1' id='konkurrenzwechsel_ja' onclick='' />
										<label for='konkurrenzwechsel_ja'>Ja, ich verfüge derzeit noch über ein gültiges Zertifikat eines anerkannten Wettbewerbers</label><br />
									</td>
								</tr>
								<tr>
									<td>
										<input type='radio' name='konkurrenzwechsel' class='rg_konkurrenzwechsel' value='0' id='konkurrenzwechsel_nein' onclick='' checked='checked' />
										<label for='konkurrenzwechsel_nein'>Nein, ich verfüge über kein noch gültiges Zertifikat eines anerkannten Wettbewerbers</label><br />
									</td>
								</tr>
							</table>
						</form>
					</div>
					</div>
				";
				if($this->api->ProductDetails->Licenses!=0) {

					$lics = '<select name="lizenzen" id="lizenzen" onclick="cc.calculate_on_update()">';
					for($i=1;$i<=100;$i++) {
						$e = $i==1?'':'en';
						$lics .= '<option value="'.$i.'">'.$i.' Lizenz'.$e.'</option>';
					}
					$lics .= '</select>';

					$out .= "
						<div class='cc_step_title'>Server-Lizenzen</div>
						<div class='cc_step_content'>
							Wenn Sie das Zertifikat auf multiplen Servern nutzen (z.B. bei mehreren Servern
							hinter einem Loadbalancer), ist hierfür je Server eine eigene Lizenz erforderlich. Bitte
							wählen Sie, wie viele Lizenzen benötigt werden.

							<form>
								<table class='cc_step_content' cellspacing='0' cellpadding='6'>
									<tr>
										<td style='background:#efefef;'>
											<strong>Bitte wählen Sie die gewünschte Anzahl an Lizenzen:</strong>
											".$lics."
										</td>
									</tr>
								</table>
							</form>
						</div>
					";
				}
				
				
				break;
			case 'STEP_CSR':
				$out .= "
						<div class='cc_step_title'>Zertifikatsantrag (CSR)</div>
						<div class='cc_step_content'>
							Kopieren Sie den erzeugten CSR in das folgende Textfeld und klicken Sie anschließend
							auf &quot;CSR überprüfen&quot;.

							<div style='background:#fffac0;border:1px solid #f3ea84;margin-top:10px;padding:8px;width:90%;margin-bottom:6px;color:#777020;' class='cc_re'>
								Bitte beachten Sie, dass alle privaten Schlüssel, die zur Erstellung des CSR dienen,
								mit 2048-bit Schlüssellänge erzeugt werden müssen. Kürzere Schlüssel sind aus
								Sicherheitsgründen nicht zulässig.
							</div>
						
							<textarea cols='65' rows='18' id='csr' name='csr' style='background:url(sslwizard/csrbg.gif) no-repeat 30px 80px;padding:10px;font-size:12px;font-family:\"Courier New\";width:90%;' onchange='cc.csr_valid=false;' onfocus='this.select()' onblur='if(this.value==\"\") { cc.validate_csr() }'></textarea>
							<div id='csr_errors' style='display:none;background:red;padding:8px;width:90%;margin-bottom:6px;margin-top:6px;color:white;' class='cc_re'></div>
							<div id='success_report' style='display:none;border:1px solid #c8dbcc;background:#f1fff4;padding:8px;width:90%;margin-bottom:6px;margin-top:6px;' class='cc_re'></div>
							<br />
							<input type='button' value='CSR überprüfen' onclick='cc.validate_csr()' />
							
						</div>				

	
						<div class='cc_step_title'>Zielplattform wählen</div>
							<div class='cc_step_content'>
							Wählen Sie, auf welcher Serversoftware das Zertifikat eingesetzt werden
							soll. Die Angabe dient primär statistischen Zwecken und ist nicht
							zwingend erforderlich.
							<div style='margin:10px;'>
								<select name='zielplattform' id='zielplattform'>
									<option value='Other' selected='selected'>Keine Angabe</value>
									<option value='apache2'>Apache 2</value>
									<option value='apachessl'>Apache + mod_ssl</value>
									<option value='apacheraven'>Apache + Raven</value>
									<option value='apachessleay'>Apache + SSLeay</value>
									<option value='c2net'>C2Net Stronghold</value>
									<option value='Ibmhttp'>IBM HTTP</value>
									<option value='Iplanet'>iPlanet Server 4.1</value>
									<option value='Dominogo4625'>Lotus Domino Go 4.6.2.51</value>
									<option value='Dominogo4626'>Lotus Domino Go 4.6.2.6+</value>
									<option value='Domino'>Lotus Domino 4.6+</value>
									<option value='iis4'>Microsoft IIS 4.0</value>
									<option value='iis5'>Microsoft IIS 5.0</value>
									<option value='iis'>Microsoft Internet Information Server</value>
									<option value='Netscape'>Netscape Enterprise/FastTrack</value>
									<option value='zeusv3'>Zeus v3+</value>
									<option value='cobaltseries'>Cobalt</value>
									<option value='cpanel'>Cpanel</value>
									<option value='ensim'>Ensim</value>
									<option value='hsphere'>Hsphere</value>
									<option value='ipswitch'>Ipswitch</value>
									<option value='plesk'>Plesk</value>
									<option value='tomcat'>Jakart Tomcat</value>
									<option value='WebLogic'>WebLogic</value>
									<option value='website'>O'Reilly WebSite Professional</value>
									<option value='webstar'>WebStar</value>
								</select>
							</div>
						</div>
				";
				break;

			case 'STEP_SAN':

				$this->api->GetProductDetails($this->payload_in->PRODUCT_CODE);

				$sancount = "<select name='sancount' id='sancount' onchange='cc.update_sancount()'>";
				$sancount .= "<option value='0'>keine</option>";
				$s = $this->api->ProductDetails->SANPackageSize;
				if($s>0&&$this->api->ProductDetails->SANPackagePrice=0.00)
					$s=0;
				else
					$s--;
				$s=0;
				$e = $this->api->ProductDetails->SANMaxHosts;
				for($i=$s;$i<$e;$i++) {
					$j = " SAN-Host".($i!=0?'s':'');
					$x = $i+1;
					$sancount .= "<option value='".$x."'>".$x.$j."</option>";
				}
				$sancount .= "</select>";
				$sancount .= "<input type='hidden' name='san_package_count' id='san_package_count' value='".$this->api->ProductDetails->SANPackageSize."' />";

				$san_host_requirements = "<strong>Dieses Produkt unterstützt folgende SAN-Features / hat folgende Anforderungen:</strong><br /><ul>";
				foreach($this->api->ProductDetails->SANFeatures as $feature) {
					$san_host_requirements .= "<li>".$this->_SANFeatureMap($feature)."</li>";
				}
				$san_host_requirements .= "</ul>";

				$out .= "
					<div class='cc_step_title'>Subject Alt. Names (SAN)</div>
					<div class='cc_step_content'>
					
						SSL-Zertifikate vom Typ <span class='highlight_productname'>".$this->api->ProductDetails->ProductName."</span> unterstützen s.g. SAN-Einträge.
						Diese ermöglichen es, multiple Hostnamen in einem Zertifikat
						unterzubringen. Dies ist oft nicht nur wirtschaftlicher, sondern erleichtert auch
						die Pflege (z.B. bei anstehender Verlängerung).
					
						<div style='margin:10px;color:#000;'><strong>Wieviele SAN Hosts werden benötigt?</strong></div>
						<div style='margin:10px;'>".$sancount."</div>

						<div id='san_pos' style='display:none;'>
							<div style='background:#fffac0;border:1px solid #f3ea84;padding:8px;width:90%;margin-bottom:6px;color:#777020;' class='cc_re'>
								".$san_host_requirements."
							</div>
							<div style='margin:10px;'><strong id='sancountspan'></strong></div>
							<div style='margin:10px;' id='sans'></div>
						</div>

					</div>
				";
				break;

			case 'STEP_CONTACTS':

				$this->api->GetProductDetails($this->payload_in->PRODUCT_CODE);
				$isVeriSignOrder = ($this->api->ProductDetails->CA=="VeriSign")?1:0;
				$isDomainValidatedOrder = in_array('DV', $this->api->ProductDetails->Features)?1:0;
				$_countries = array(
					'AF'=>'Afghanistan',
					'AL'=>'Albanien',
					'DZ'=>'Algerien',
					'AD'=>'Andorra',
					'AG'=>'Antigua',
					'AR'=>'Argentinien',
					'AM'=>'Armenien',
					'AU'=>'Australien',
					'BE'=>'Belgien',
					'BO'=>'Bolivien',
					'BA'=>'Bosnien und Herzigova',
					'BR'=>'Brasilien',
					'BG'=>'Bulgarien',
					'CL'=>'Chile',
					'CN'=>'China',
					'CR'=>'Costa Rica',
					'DE'=>'Deutschland',
					'DO'=>'Dominikanische Republik',
					'DK'=>'Dänemark',
					'EC'=>'Equador',
					'FI'=>'Finnland',
					'FR'=>'Frankreich',
					'GM'=>'Gambia',
					'GE'=>'Georgien',
					'GI'=>'Gibraltar',
					'GR'=>'Griechenland',
					'UK'=>'Großbritannien',
					'GL'=>'Grönland',
					'HT'=>'Haiti',
					'HN'=>'Honduras',
					'HK'=>'Hongkong',
					'IN'=>'Indien',
					'ID'=>'Indonesien',
					'IS'=>'Island',
					'IM'=>'Isle of Man',
					'IL'=>'Israel',
					'IT'=>'Italien',
					'JM'=>'Jamaika',
					'JP'=>'Japan',
					'CA'=>'Kanada',
					'KZ'=>'Kasachstan',
					'KE'=>'Kenia',
					'CO'=>'Kolumbien',
					'CG'=>'Kongo',
					'HR'=>'Kroatien',
					'CU'=>'Kuba',
					'KW'=>'Kuwait',
					'LI'=>'Liechtenstein',
					'LT'=>'Litauen',
					'LU'=>'Luxemburg',
					'MG'=>'Madagaskar',
					'MY'=>'Malaysia',
					'MV'=>'Malediven',
					'MT'=>'Malta',
					'MU'=>'Mauritius',
					'MK'=>'Mazedonien',
					'MX'=>'Mexiko',
					'MD'=>'Moldavien',
					'MC'=>'Monaco',
					'NZ'=>'Neuseeland',
					'NL'=>'Niederlande',
					'PL'=>'Polen',
					'PT'=>'Portugal',
					'QA'=>'Qatar',
					'RU'=>'Russland',
					'SA'=>'Saudi Arabien',
					'SE'=>'Schweden',
					'CH'=>'Schweiz',
					'RS'=>'Serbien',
					'SG'=>'Singapur',
					'SI'=>'Slovenien',
					'ES'=>'Spanien',
					'LK'=>'Sri Lanka',
					'ZA'=>'Süd Afrika',
					'TW'=>'Taiwan',
					'TZ'=>'Tansania',
					'TH'=>'Thailand',
					'CZ'=>'Tschechische Republik',
					'TN'=>'Tunesien',
					'TK'=>'Türkei',
					'US'=>'USA',
					'UA'=>'Ukraine',
					'HU'=>'Ungarn',
					'CY'=>'Zypern',
					'EG'=>'Ägypten',
					'AT'=>'Österreich',
				);
				$countries = "";
				foreach($_countries as $c_code => $c_name) {
					$_sel = ($c_code=='DE'?' selected="selected"':'');
					$countries .= "<option value='".$c_code."'".$_sel.">".$c_name."</option>";
				}

				$out .= "
					<div class='cc_step_title'>Technischer Ansprechpartner</div>
					<div class='cc_step_content'>

						Der technische Ansprechpartner empfängt das fertig ausgestellt Zertifikat in Kopie.
						Hier kann z.B. die Anschrift des technischen Dienstleisters stehen.

						<div style='margin:10px;'>
						<table style='border:0;' cellpadding='5'>
							<tr class='requiredForVeriSignOrders'>
								<td style='text-align:right;font-weight:bold;width:140px;'>Organisation/Firma:</td>
								<td><input type='text' style='width:300px;' maxlength='100' value='' class='inputCompanies' name='tech_organisation' id='tech_organisation' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;width:140px;'>* Vorname:</td>
								<td><input class='required' err='Vorname' type='text' style='width:300px;' maxlength='100' value='' name='tech_vorname' id='tech_vorname' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>* Nachname:</td>
								<td><input class='required' err='Nachname' type='text' style='width:300px;' maxlength='100' value='' name='tech_nachname' id='tech_nachname' /></td>
							</tr>
							<tr class='requiredForDVandSSL123'>
								<td style='text-align:right;font-weight:bold;'>* Position:</td>
								<td><input class='required' err='Position' type='text' style='width:300px;' maxlength='100' value='' name='tech_position' id='tech_position' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>* E-Mail Adresse:</td>
								<td><input class='required' err='E-Mail Addresse' type='text' style='width:300px;' maxlength='320' value='' name='tech_email' id='tech_email' /></td>
							</tr>
							<tr class='requiredForVeriSignOrders'>
								<td style='text-align:right;font-weight:bold;'>* Adresse:</td>
								<td><input class='required' err='Anschrift' type='text' style='width:300px;' maxlength='100' value='' name='tech_adresse1' id='tech_adresse1' /></td>
							</tr>
							<tr class='requiredForVeriSignOrders'>
								<td></td>
								<td><input type='text' style='width:300px;' maxlength='100' value='' name='tech_adresse2' id='tech_adresse2' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>* Telefon:</td>
								<td><input class='required' err='Telefon' type='text' style='width:300px;' maxlength='30' value='' name='tech_telefon' id='tech_telefon' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>Telefax:</td>
								<td><input type='text' style='width:300px;' maxlength='30' value='' name='tech_telefax' id='tech_telefax' /></td>
							</tr>
							<tr class='requiredForVeriSignOrders'>
								<td style='text-align:right;font-weight:bold;'>* Postleitzahl:</td>
								<td><input class='required' err='Postleitzahl' type='text' style='width:300px;' maxlength='20' value='' name='tech_plz' id='tech_plz' /></td>
							</tr>
							<tr class='requiredForVeriSignOrders'>
								<td style='text-align:right;font-weight:bold;'>* Ort:</td>
								<td><input err='Ort' type='text' style='width:300px;' maxlength='64' class='inputCities required' value='' name='tech_ort' id='tech_ort' /></td>
							</tr>
							<tr class='requiredForVeriSignOrders'>
								<td style='text-align:right;font-weight:bold;'>* Region/Bundesland:</td>
								<td><input err='Region/Bundesland' type='text' style='width:300px;' maxlength='64' class='inputRegions required' value='' name='tech_region' id='tech_region' /></td>
							</tr>
							<tr class='requiredForVeriSignOrders'>
								<td style='text-align:right;font-weight:bold;'>* Land:</td>
								<td>
									<select style='width:300px;' name='tech_land' id='tech_land' class='inputCountries'>".$countries."</select>
								</td>
							</tr>
						</table>
						</div>
					</div>

					<input type='hidden' name='isVeriSignOrder' id='isVeriSignOrder' value='".$isVeriSignOrder."' />
					<input type='hidden' name='isDomainValidatedOrder' id='isDomainValidatedOrder' value='".$isDomainValidatedOrder."' />
					<input type='hidden' name='ProductCode' id='ProductCode' value='".$this->payload_in->PRODUCT_CODE."' />

					<div class='cc_step_title'>Administrativer Ansprechpartner</div>
					<div class='cc_step_content'>

						Der administrative Ansprechpartner empfängt das SSL-Zertifikat. Bei unternehmensvalidierten und EV-Zertifikaten
						ist dieser Ansprechpartner auch für die Validierung des Zertifikats innerhalb des Unternehmens zuständig und
						wird diesbezüglich von der ausstellenden CA über die Personalabteilung des Antragstellers kontaktiert.

						<div style='margin:10px;'>
						<table style='border:0;' cellpadding='5'>
							<tr>
								<td colspan='2' style='text-align:right;font-size:0.9em;'>
									<a href='JavaScript:void(0);' onclick='cc.contact_copy(\"tech\",\"admin\")' style='text-decoration:underline;'>Daten von technischem Ansprechpartner übernehmen</a>
								</td>
							</tr>
							<tr class='requiredForVeriSignOrders'>
								<td style='text-align:right;font-weight:bold;width:140px;'>Organisation/Firma:</td>
								<td><input type='text' style='width:300px;' maxlength='100' value='' class='inputCompanies' name='admin_organisation' id='admin_organisation' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;width:140px;'>* Vorname:</td>
								<td><input class='required' err='Vorname' type='text' style='width:300px;' maxlength='100' value='' name='admin_vorname' id='admin_vorname' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>* Nachname:</td>
								<td><input class='required' err='Nachname' type='text' style='width:300px;' maxlength='100' value='' name='admin_nachname' id='admin_nachname' /></td>
							</tr>
							<tr class='requiredForDVandSSL123'>
								<td style='text-align:right;font-weight:bold;'>* Position:</td>
								<td><input class='required' err='Position' type='text' style='width:300px;' maxlength='100' value='' name='admin_position' id='admin_position' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>* E-Mail Adresse:</td>
								<td><input class='required' err='E-Mail Adresse' type='text' style='width:300px;' maxlength='320' value='' name='admin_email' id='admin_email' /></td>
							</tr>
							<tr class='requiredForVeriSignOrders'>
								<td style='text-align:right;font-weight:bold;'>* Adresse:</td>
								<td><input class='required' err='Anschrift' type='text' style='width:300px;' maxlength='100' value='' name='admin_adresse1' id='admin_adresse1' /></td>
							</tr>
							<tr class='requiredForVeriSignOrders'>
								<td></td>
								<td><input type='text' style='width:300px;' maxlength='100' value='' name='admin_adresse2' id='admin_adresse2' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>* Telefon:</td>
								<td><input class='required' err='Telefon' type='text' style='width:300px;' maxlength='30' value='' name='admin_telefon' id='admin_telefon' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>Telefax:</td>
								<td><input type='text' style='width:300px;' maxlength='30' value='' name='admin_telefax' id='admin_telefax' /></td>
							</tr>
							<tr class='requiredForVeriSignOrders'>
								<td style='text-align:right;font-weight:bold;'>* Postleitzahl:</td>
								<td><input class='required' err='Telefax' type='text' style='width:300px;' maxlength='20' value='' name='admin_plz' id='admin_plz' /></td>
							</tr>
							<tr class='requiredForVeriSignOrders'>
								<td style='text-align:right;font-weight:bold;'>* Ort:</td>
								<td><input err='Ort' type='text' style='width:300px;' maxlength='64' class='inputCities required' value='' name='admin_ort' id='admin_ort' /></td>
							</tr>
							<tr class='requiredForVeriSignOrders'>
								<td style='text-align:right;font-weight:bold;'>* Region/Bundesland:</td>
								<td><input err='Region/Bundesland' type='text' style='width:300px;' maxlength='64' class='inputRegions required' value='' name='admin_region' id='admin_region' /></td>
							</tr>
							<tr class='requiredForVeriSignOrders'>
								<td style='text-align:right;font-weight:bold;'>* Land:</td>
								<td>
									<select style='width:300px;' name='admin_land' id='admin_land' class='inputCountries'>".$countries."</select>
								</td>
							</tr>
						</table>
						</div>
					</div>

					<div class='cc_step_title'>Anschrift des Unternehmens</div>
					<div class='cc_step_content'>
						Bitte geben Sie die Anschrift des Unternehmens ein. Bei Zertifikaten mit
						Unternehmensvalidierung (auch bei EV-Zertifikaten) ist es erforderlich, die
						Adresse gemäß Handelsregister-Auszug anzugeben.

						<div style='margin:10px;'>
						<table style='border:0;' cellpadding='5'>
							<tr>
								<td colspan='2' style='text-align:right;font-size:0.9em;'>
									<a href='JavaScript:void(0);' onclick='cc.contact_copy(\"tech\",\"org\")' style='text-decoration:underline;'>Daten von technischem Ansprechpartner übernehmen</a>
									<br />
									<a href='JavaScript:void(0);' onclick='cc.contact_copy(\"admin\",\"org\")' style='text-decoration:underline;'>Daten von administrativem Ansprechpartner übernehmen</a>
									<br />
								</td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;width:140px;'>Organisation/Firma:</td>
								<td><input type='text' style='width:300px;' disabled='disabled' maxlength='100' value='' class='inputCompanies' name='admin_organisation' id='org_organisation' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>* Adresse:</td>
								<td><input class='required' err='Anschrift' type='text' style='width:300px;' maxlength='100' value='' name='org_adresse1' id='org_adresse1' /></td>
							</tr>
							<tr>
								<td></td>
								<td><input type='text' style='width:300px;' maxlength='100' value='' name='org_adresse2' id='org_adresse2' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>* Telefon:</td>
								<td><input class='required' err='Telefon' type='text' style='width:300px;' maxlength='64' value='' name='org_telefon' id='org_telefon' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>Telefax:</td>
								<td><input type='text' style='width:300px;' maxlength='64' value='' name='org_telefax' id='org_telefax' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>* Postleitzahl:</td>
								<td><input class='required' err='Postleitzahl' type='text' style='width:300px;' maxlength='20' value='' name='org_plz' id='org_plz' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>* Ort:</td>
								<td><input err='Ort' type='text' style='width:300px;' disabled='disabled' maxlength='64' class='inputCities required' value='' name='org_ort' id='org_ort' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>* Region/Bundesland:</td>
								<td><input err='Region/Bundesland' type='text' style='width:300px;' disabled='disabled' maxlength='64' class='inputRegions required' value='' name='org_region' id='org_region' /></td>
							</tr>
							<tr>
								<td style='text-align:right;font-weight:bold;'>* Land:</td>
								<td>
									<select style='width:300px;' disabled='disabled' class='inputCountries' name='org_land' id='org_land'>".$countries."</select>
								</td>
							</tr>
						</table>
						</div>
					</div>

					<div id='block_approver' style='min-height:10px;display:none;'>
					<div class='cc_step_title'>Ansprechpartner für E-Mail-Bestätigung</div>
					<div class='cc_step_content'>

						Bei dem gewählten Zertifikatstyp ist die Bestätigung einer E-Mail
						notwendig. Bitte wählen Sie eine Adresse, die Sie unverzüglich
						empfangen können. Erst nach Bestätigung der E-Mail, die direkt
						nach Auftragsübermittlung versand wird, kann der Auftrag weiter
						bearbeitet werden.

						<div style='margin:10px;' id='approvers'></div>
					</div>
					</div>

					";
				break;

			case 'STEP_DONE':

				$out .= "
					<div id='block_order_status'>
						<div class='cc_step_title'>Auftrag übermitteln</div>
						<div class='cc_step_content' id='order_waiting'>
							<img src='sslwizard/loader.gif' hspace='5' alt='' title='' /> <em>Bitte haben Sie ein wenig Geduld, Ihr Auftrag wird übermittelt ..</em>
						</div>
						<div id='order_status' style='display:none;padding:20px;'></div>
						</div>
					</div>
				";
				break;

		}
		return $out;
	}

	private function wizard_load() {

		$out = $this->_html_construct();
		$steps = $this->steps;
		$i=0;

		foreach($steps as $step_alias => $step_title) {
			$out .= "<div id='cc_step_".($i+1)."' style='display:none;' class='cc_step".($this->payload_in->STEP==$i+1?' cc_step_active':'')."'>".$this->content_load($step_alias)."</div>";
			$i+=1;
		}

		unset($steps);

		$out .= "
			<div id='cc_step_navigator'>
				<div style='float:left;' id='livePriceC'><span style='font-weight:bold;'>Aktueller Preis:</span><span id='livePrice'></span></div>
				<div style='float:right;margin-left:10px;'><input type='button' id='step_next' disabled='disabled' value='Nächster Schritt &raquo;' onclick='cc.btn_next()' /></div>
				<div style='float:right;'><input type='button' id='step_prev' disabled='disabled' value='&laquo; Vorheriger Schritt' onclick='cc.btn_prev()' /></div>
				<div class='cc_cb'></div>
			</div>
		";

		return $out;
	}
}

$cc = new CC_INSTANCE();

?>
