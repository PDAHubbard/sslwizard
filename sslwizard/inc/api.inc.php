<?php
#
# AJAX-based SSL-Wizard
# Copyright (c) 2011 CertCenter AG
#
# Diese Software wird registrierten Partnern der CertCenter AG kostenfrei zur Verfügung gestellt.
# Die Software unterliegt jedoch keiner OpenSource-Lizenz, weshalb die unautorisierte Verbreitung
# nicht gestattet ist. Der Quelltext dieser Software unterliegt der Geheimhaltungsvereinbarung.
#

/*
	Klasse zur Kommunikation mit dem Server
	der CertCenter AG
*/
class CC_API {

	private $api_uri = 'https://api.certcenter.de';
	public $client = false;
	public $ProductDetails;
	public $WizardInfos;
	public $ParsedCSR;
	public $errors, $errors_raw;
	public $Limit;

	function __construct() {
		try {
			$this->client = new SoapClient(
				$this->api_uri."/wsdl",
				array(
					'soap_version'=>SOAP_1_2,
					'encoding'=>'UTF-8',
					'trace'=>0
				)
			);
		} catch(Exception $e) {
			die("Der SSL-Wizard konnte nicht initialisiert werden [temporärer Verbindungsfehler].<br />Bitte versuchen Sie es in Kürze erneut!");
		}
	}
	
	public function GetLimit() {
		$req = $this->base_request_header();
		$x = @$this->client->GetLimit($req);
		if(@is_soap_fault($x)) return false;
		elseif(@$x->GetLimitResult->QueryResponseHeader->SuccessCode!='0') return false;
		$this->Limit = $x->GetLimitResult->LimitInfo;
		return true;
	}
	
	public function GetProductDetails($ProductCode) {
		$req = $this->base_request_header();
		$req['Request']['RequestHeader']['ProductCode']=$ProductCode;
		$x = @$this->client->GetProductDetails($req);
		if(@is_soap_fault($x)) return false;
		elseif(@$x->GetProductDetailsResult->QueryResponseHeader->SuccessCode!='0') return false;
		$this->ProductDetails = $x->GetProductDetailsResult->ProductDetails;
		$this->ProductDetails->Features = explode(",", $this->ProductDetails->Features);
		$this->ProductDetails->SANFeatures = explode(",", $this->ProductDetails->SANFeatures);
		return true;
	}

	public function GetWizardInfos($ProductCode) {
		$req = $this->base_request_header();
		$req['Request']['RequestHeader']['ProductCode']=$ProductCode;
		$x = $this->client->GetWizardInfos($req);
		if(is_soap_fault($x)) return false;
		elseif(@$x->GetWizardInfosResult->QueryResponseHeader->SuccessCode!='0') return false;
		$this->WizardInfos = $x->GetWizardInfosResult->WizardInfos;
		$this->WizardInfos->MultiYearPricing = explode("|",$this->WizardInfos->MultiYearPricing);
		return true;
	}
	
	public function GetWizardQuote($ProductCode,$ValidityPeriod=12,$ServerCount=1,$SANCount=0) {
		$req = $this->base_request_header();
		$req['Request']['RequestHeader']['ProductCode']=$ProductCode;
		$req['Request']['WizardQuoteParams']=array(
			'ValidityPeriod' => $ValidityPeriod,
			'ServerCount' => $ServerCount,
			'SANCount' => $SANCount,
		);
		$x = @$this->client->GetWizardQuote($req);
		if(@is_soap_fault($x)) return false;
		elseif(@$x->GetWizardQuoteResult->QueryResponseHeader->SuccessCode!='0') return false;
		$this->WizardQuote = $x->GetWizardQuoteResult->Price;
		if($this->WizardQuote==-1) return false;
		return true;
	}

	public function ValidateCSR($csr) {
		$req = $this->base_request_header();
		$req['Request']['CSR']=$csr;
		$x = $this->client->ValidateCSR($req);
		if(is_soap_fault($x)) return false;
		elseif(@$x->ValidateCSRResult->QueryResponseHeader->SuccessCode!='0') return false;
		$this->ParsedCSR = $x->ValidateCSRResult->ParsedCSR;
		return true;
	}
	
	public function GetApproverList($ProductCode,$domain) {
		$req = $this->base_request_header();
		$req['Request']['RequestHeader']['ProductCode']=$ProductCode;
		$req['Request']['Domain']=$domain;
		$x = @$this->client->GetApproverList($req);
		if(@is_soap_fault($x)) return false;
		elseif(@$x->GetApproverListResult->QueryResponseHeader->SuccessCode!='0') return false;
		return $x->GetApproverListResult->ApproverList->Approver;
	}
	
	public function GetModifiedOrders($FromDate,$ToDate) {
		$req = $this->base_request_header();
		$req['Request']['FromDate']=$FromDate;
		$req['Request']['ToDate']=$ToDate;
		$x = $this->client->GetModifiedOrders($req);
		if(@is_soap_fault($x)) return false;
		elseif(@$x->GetModifiedOrdersResult->QueryResponseHeader->SuccessCode!='0') return false;
		return $x->GetModifiedOrdersResult;
	}


	public function Order($data, $ProductCode) {
		$req = $this->base_request_header();

		$PartnerOrderID = "CertCenter-WIZ-".CC_WIZARD_VERSION."-".substr(md5(@microtime()),20);
		
		# RequestHeader
		$req['Request']['RequestHeader']['ProductCode']=$ProductCode;
		$req['Request']['RequestHeader']['PartnerOrderID']=$PartnerOrderID;

		# OrderParameters
		$req['Request']['OrderParameters']=array(
			'ValidityPeriod' => $data->validity_period,
			'WebServerType' => $data->platform,
			'RenewalIndicator' => $data->renew,
			'CUIndicator' => $data->cupgrade,
			'CSR' => $_POST['CSR'],
		);
		if($data->licenses!=0)
			$req['Request']['OrderParameters']['ServerCount']=$data->licenses;
			
		# Competitive Upgrade / SANs / Approver E-Mail
		if(trim($_POST['CUCertificate'])!='')
			$req['Request']['OrderParameters']['CUCertificate'] = $_POST['CUCertificate'];
		if(key_exists('san_hosts', $data))
			$req['Request']['OrderParameters']['DNSNames'] = implode(",", $data->san_hosts);
		if(key_exists('approver_email', $data))
			$req['Request']['ApproverEmail'] = $data->approver_email;

		$req['Request']['OrganizationInfo']=array();
		$address = array();
		$item = $data->contact_org;
		if(key_exists('organisation', $item))	$req['Request']['OrganizationInfo']['OrganizationName'] = $item->organisation;
		if(key_exists('adresse1', $item))		$address['AddressLine1'] = $item->adresse1;
		if(key_exists('adresse2', $item))		$address['AddressLine2'] = $item->adresse2;
		if(key_exists('ort', $item))			$address['City'] = $item->ort;
		if(key_exists('region', $item))			$address['Region'] = $item->region;
		if(key_exists('plz', $item))			$address['PostalCode'] = $item->plz;
		if(key_exists('land', $item))			$address['Country'] = $item->land;
		if(key_exists('telefon', $item))		$address['Phone'] = $item->telefon;
		if(key_exists('telefax', $item))		$address['Fax'] = $item->telefax;
		$req['Request']['OrganizationInfo']['OrganizationAddress']=$address;
		unset($address);

		$address = array();
		$item = $data->contact_admin;
		if(key_exists('vorname', $item))		$address['FirstName'] = $item->vorname;
		if(key_exists('nachname', $item))		$address['LastName'] = $item->nachname;
		if(key_exists('telefon', $item))		$address['Phone'] = $item->telefon;
		if(key_exists('email', $item))			$address['Email'] = $item->email;
		if(key_exists('position', $item))		$address['Title'] = $item->position;
		if(key_exists('telefax', $item))		$address['Fax'] = $item->telefax;
		if(key_exists('organisation', $item))	$address['OrganizationName'] = $item->organisation;
		if(key_exists('adresse1', $item))		$address['AddressLine1'] = $item->adresse1;
		if(key_exists('adresse2', $item))		$address['AddressLine2'] = $item->adresse2;
		if(key_exists('ort', $item))			$address['City'] = $item->ort;
		if(key_exists('region', $item))			$address['Region'] = $item->region;
		if(key_exists('plz', $item))			$address['PostalCode'] = $item->plz;
		if(key_exists('land', $item))			$address['Country'] = $item->land;
		$req['Request']['AdminContact']=$address;
		unset($address);

		$address = array();
		$item = $data->contact_tech;
		if(key_exists('vorname', $item))		$address['FirstName'] = $item->vorname;
		if(key_exists('nachname', $item))		$address['LastName'] = $item->nachname;
		if(key_exists('telefon', $item))		$address['Phone'] = $item->telefon;
		if(key_exists('email', $item))			$address['Email'] = $item->email;
		if(key_exists('position', $item))		$address['Title'] = $item->position;
		if(key_exists('telefax', $item))		$address['Fax'] = $item->telefax;
		if(key_exists('organisation', $item))	$address['OrganizationName'] = $item->organisation;
		if(key_exists('adresse1', $item))		$address['AddressLine1'] = $item->adresse1;
		if(key_exists('adresse2', $item))		$address['AddressLine2'] = $item->adresse2;
		if(key_exists('ort', $item))			$address['City'] = $item->ort;
		if(key_exists('region', $item))			$address['Region'] = $item->region;
		if(key_exists('plz', $item))			$address['PostalCode'] = $item->plz;
		if(key_exists('land', $item))			$address['Country'] = $item->land;
		$req['Request']['TechContact']=$address;
		unset($address);

		$x = @$this->client->Order($req);
		
		if(@is_soap_fault($x)) {
			return false;
		} elseif(@$x->OrderResult->ResponseHeader->SuccessCode!='0') {
			$res = $x->OrderResult;
			$errors = array();
			if(is_object($res->ResponseHeader->Errors->Error)) {
				$x = $res->ResponseHeader->Errors->Error;
				unset($res->ResponseHeader->Errors);
				@$res->ResponseHeader->Errors->Error = array();
				@$res->ResponseHeader->Errors->Error[0] = $x;
			}
			$arr = $res->ResponseHeader->Errors->Error;
			foreach($arr as $error) {
				$msg = $error->ErrorMessage;
				if(isset($error->ErrorField))
					$msg .=" (".$error->ErrorField.")";
				$errors[]=$msg;
			}
			$this->errors_raw = "- ".implode("\n- ", $errors);
			$this->errors = "<ul class='errors'><li>".implode("</li><li>", $errors)."</li></ul>";
			return false;
		}
		return $x->OrderResult->CertCenterOrderID;
	}

	private function base_request_header() {
		return array('Request'=>array('RequestHeader'=>array('AuthToken'=>
			array('Username'=>CC_API_USERNAME,'Password'=>CC_API_PASSWORD))));
	}

};


?>
