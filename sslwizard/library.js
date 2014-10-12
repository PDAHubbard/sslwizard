
SSLWIZARD = Class.create({

	initialized: false,
	signature: '--- BEGIN AJAX BASED SSL-WIZARD ---',
	current_step: 1,
	tax: 19,
	last_step: 1,
	max_steps: 0,
	csr_valid: false,
	errors: Array(),

	/*
		Initialisiert den Wizard auf der Website und
		lädt den 1. Schritt des Bestellprozesses
	*/
	initialize: function() {
		if(this.initialized)
			return false
		if(!$('cc_ssl_wizard')) {
			alert("Das SSL-Wizard-Modul konnte nicht initialisiert werden.\nBitte kontaktieren Sie den Kundenservice.")
			return false
		}
		this.product_code = $('cc_ssl_wizard').getAttribute('product')
		if(this.product_code==null) {
			alert("Das SSL-Modul konnte nicht initialisiert werden, da keine SSL Produktkennung übergeben wurde.")
			return false
		}
		
		tax = $('cc_ssl_wizard').getAttribute('tax')
		if(tax!=null) this.tax = tax

		this.html('<em style="color:gray;font-size:0.9em;">SSL-Wizard wird initialisiert ..</em>');

		this.load_layout()

		this.initialized = true

		this.debug("Initialisierung erfolgreich.")
	},
	
	html: function(buf) {
		$('cc_ssl_wizard').update(buf)
	},

	debug: function(buf) {
		//if(!$('cc_ssl_wizard_debug')) return false
		//$('cc_ssl_wizard_debug').update(buf+"<br />"+$('cc_ssl_wizard_debug').innerHTML)
	},
	
	/* Prueft, ob Antwort vom Server verwertbar ist
	*/
	check_signature: function(x) {
		if(x.responseText.substr(0,this.signature.length) != this.signature) {
			this.debug("'"+x.responseText.substr(0,this.signature.length)+"'")
			return false
		}
		return true
	},
	
	/* Entfernt Signatur aus Antwort
	*/
	xcrop: function(x) {
		x.responseText=x.responseText.substr(cc.signature.length, x.responseText.length)
		return x
	},
	
	/* Handlet Zurueck- und Weiter-Buttons
	*/
	toggle_nextprev: function() {
		$('step_prev').disabled=false
		$('step_next').disabled=false
		if(this.current_step==1)
			$('step_prev').disabled=true
		else if(this.current_step==this.max_steps) {
			$('step_prev').disabled=true
			$('step_next').disabled=true
			this.execute()
		}
		$('cc_nav_item_step'+this.last_step).removeClassName('cc_nav_item_active')
		$('cc_nav_item_step'+this.current_step).addClassName('cc_nav_item_active')
		this.calculate_on_update()
		scroll(0,0)
	},
	
	get_current_nav_alias: function() {
		return $$('.cc_nav_item_active')[0].getAttribute('alias')
	},
	
	btn_prev: function() {
		var new_step = this.current_step-1;
		$('cc_step_'+this.current_step).hide()
		$('cc_step_'+new_step).show()
		this.last_step = this.current_step
		this.current_step = new_step
		this.toggle_nextprev()
	},

	btn_next: function() {
		var last_alias = this.get_current_nav_alias()
		if(last_alias=='STEP_CONTACTS') {
			if(!this.validate_contacts()) {
				scroll(0,0)
				return false
			}
		}
		var new_step = this.current_step+1;
		$('cc_step_'+this.current_step).hide()
		$('cc_step_'+new_step).show()
		this.last_step = this.current_step
		this.current_step = new_step
		if(this.current_step>2&&this.last_step==2&&!this.csr_valid) {
			this.validate_csr()
			if(!this.csr_valid)
				this.btn_prev()
		}
		this.toggle_nextprev()
	},
	
	validate_contacts: function() {
		cc.errors = Array()
		$$('.required').each(function(x) {
			if(x.value=='') {
				var feld = x.getAttribute("err")
				var bereich = x.name.split("_")[0]
				if(bereich=='tech') bereich = "unter \"Technischer Ansprechpartner\" aus"
				else if(bereich=='admin') bereich = "unter \"Administrativer Ansprechpartner\" aus"
				else if(bereich=='org') bereich = "unter \"Anschrift des Unternehmens\" aus"
				else bereich = ""
				cc.errors[cc.errors.length]="- Bitte füllen Sie das Feld \""+feld+"\""+bereich+"\n"
			}
		})
		if(cc.errors.length) {
			errors = ""
			for(var i=0;i<cc.errors.length;i++)
				errors = errors + cc.errors[i]
			alert(errors)
			return false
		}
		return true
	},

	get_radio_value: function(group) {
		var value = false
		$$(".rg_"+group).each(function(x) { if(x.checked) value=x.value })
		return value
	},

	validate_csr: function() {
		var laufzeit=this.get_radio_value('laufzeit')
		var verlaengerung=this.get_radio_value('verlaengerung')
		var konkurrenzwechsel=this.get_radio_value('konkurrenzwechsel')
		this.csr_valid = false
		var csr = $F('csr')
		if(csr=='') {
			$('csr_errors').hide()
			$('success_report').hide()
			return;
		}
		var lizenzen=1;
		$('csr_errors').hide();
		$('success_report').hide();
		if($('lizenzen')) lizenzen=$F('lizenzen')
		payload_out = {
				'ACTION': 				'validate_csr',
				'PRODUCT_CODE': 		this.product_code
		}
		new Ajax.Request( document.location+'', {
			method: 'POST',
			asynchronous: false,
			onSuccess: function(x) {
				if(!cc.check_signature(x)) {
					alert("Der PHP-Code für den SSL-Wizard wurde nicht korrekt auf der Website eingebunden oder es ist ein PHP-Fehler aufgetreten. "+x.responseText)
					return false
				}
				x = cc.xcrop(x)
				cc.debug("AJAX-Request erfolgreich!");
				payload_in = x.responseText.evalJSON()
				if(payload_in['STATUS'] == 'failure') {
					cc.csr_valid = false
					$('csr_errors').show().update(payload_in['ERROR_MSG'])
					return false
				}
				$('success_report').show().update(payload_in['HTML'])

				$$('.inputCompanies').each(function(x) { x.value=payload_in['CSRDATA']['Organization'] })
				$$('.inputCities').each(function(x) { x.value=payload_in['CSRDATA']['Locality'] })
				$$('.inputRegions').each(function(x) { x.value=payload_in['CSRDATA']['State'] })
				$$('.inputCountries').each(function(x) { x.value=payload_in['CSRDATA']['Country'] })

				cc.csr_valid = true
				
				$('block_approver').hide()
				$('approvers').update("")
				if($F('isDomainValidatedOrder')=='1'||$F('ProductCode')=='TrueBusinessIDEV') {
					if(payload_in['APPROVERS']) {
						$('block_approver').show()
						$('approvers').update(payload_in['APPROVERS'])
					}
				}

			},
			parameters: { 'PAYLOAD': Object.toJSON(payload_out), 'CSR': csr }
		})
	},
	
	execute: function() {
		var payload = new Object()

		// Schritt 1 (Allgemeine Optionen)

		// - Gueltigkeitsdauer
		payload.validity_period = this.get_radio_value("laufzeit")*12
		// - Verlaengerung (ggf. mit Cert)
		v=this.get_radio_value("verlaengerung")
		payload.renew = v==0||v==false?false:true
		// - Konkurrenzwechsel (ggf. mit Cert)
		v=this.get_radio_value("konkurrenzwechsel")
		payload.cupgrade = v==0||v==false?false:true
		var CUCertificate = ""
		if($('CUCertificate'))
			CUCertificate = $F('CUCertificate')
		// - Lizenzen
		payload.licenses = 0
		if($('lizenzen'))
			payload.licenses = $F('lizenzen')

		// Schritt 2 (Zertifikatsantrag (CSR))

		// - Zielplattform
		payload.platform = $F('zielplattform')

		// Schritt 3 (Subject Alt. Names (SAN))

		// - SAN-Hosts
		payload.san_hosts = Array()
		$$('.SANhosts').each(function(x) { payload.san_hosts[payload.san_hosts.length]=x.value })

		// Schritt 4 (Ansprechpartner)

		// - Technischer AP
		payload.contact_tech = this.get_contact_data('tech')
		// - Administrativer AP
		payload.contact_admin = this.get_contact_data('admin')
		// - Unternehmensanschrift
		payload.contact_org = this.get_contact_data('org')
		// - Approver E-Mail
		x = this.get_radio_value("approver_email")
		if(x!=false)
			payload.approver_email = x

		$('order_status').update("").hide()
		$('order_waiting').appear();

		payload = {
			'ACTION': 				'order',
			'TAX':					this.tax,
			'PRODUCT_CODE': 		this.product_code,
			'ORDER': 				payload
		}

		$('step_prev').disabled=true;

		new Ajax.Request(document.location+'', {
			method: 'POST',
			onSuccess: function(x) {
				if(!cc.check_signature(x)) {
					alert("Der PHP-Code für den SSL-Wizard wurde nicht korrekt auf der Website eingebunden oder es ist ein PHP-Fehler aufgetreten. "+x.responseText)
						return false;
				}
				x = cc.xcrop(x)
				cc.debug("Bestellung wurde übermittelt")
				cc.debug(x.responseText)
				payload_in = x.responseText.evalJSON()
				if(payload_in['STATUS']=='success') {
					$('order_waiting').hide()
					$('order_status').update(payload_in['HTML']).setStyle({'fontWeight':'normal','color':'green', 'backgroundColor':''}).show()
					$('step_prev').disabled=true;
				} else {
					cc.debug(x.responseText)
					$('order_waiting').hide()
					$('order_status').update(payload_in['HTML']).setStyle({'fontWeight':'normal','color':'white', 'backgroundColor':'red' }).show()
					$('step_prev').disabled=false;
				}
			},
			onFailure: function(x) {
				$('step_prev').disabled=false;
				$('order_status').update('Es ist ein unerwarteter Fehler aufgetreten. Bitte wenden Sie sich an den Kundenservice.').appear()
			},
			parameters: {
				'PAYLOAD': Object.toJSON(payload),
				'CSR': $F('csr'),
				'CUCertificate': CUCertificate	
			}
		})
	},

	get_contact_data: function(from) {
		var data = new Object()
		if($(from+'_organisation')) data.organisation=$F(from+'_organisation')
		if($(from+'_vorname')) data.vorname=$F(from+'_vorname')
		if($(from+'_nachname')) data.nachname=$F(from+'_nachname')
		if($(from+'_position')) data.position=$F(from+'_position')
		if($(from+'_email')) data.email=$F(from+'_email')
		if($(from+'_adresse1')) data.adresse1=$F(from+'_adresse1')
		if($(from+'_adresse2')) data.adresse2=$F(from+'_adresse2')
		if($(from+'_telefon')) data.telefon=$F(from+'_telefon')
		if($(from+'_telefax')) data.telefax=$F(from+'_telefax')
		if($(from+'_plz')) data.plz=$F(from+'_plz')
		if($(from+'_ort')) data.ort=$F(from+'_ort')
		if($(from+'_region')) data.region=$F(from+'_region')
		if($(from+'_land')) data.land=$F(from+'_land')
		return data
	},

	contact_copy: function(from,to) {
		if(to!='org') {
			if($(to+'_organisation')) $(to+'_organisation').value=""
			if($(from+'_organisation') && $(to+'_organisation'))	$(to+'_organisation').value=$F(from+'_organisation')
		}
		if($(to+'_vorname')) $(to+'_vorname').value=""
		if($(from+'_vorname') && $(to+'_vorname'))				$(to+'_vorname').value=$F(from+'_vorname')
		if($(to+'_nachname')) $(to+'_nachname').value=""
		if($(from+'_nachname') && $(to+'_nachname'))			$(to+'_nachname').value=$F(from+'_nachname')
		if($(to+'_position')) $(to+'_position').value=""
		if($(from+'_position') && $(to+'_position'))			$(to+'_position').value=$F(from+'_position')
		if($(to+'_email')) $(to+'_email').value=""
		if($(from+'_email') && $(to+'_email'))					$(to+'_email').value=$F(from+'_email')
		if($(to+'_adresse1')) $(to+'_adresse1').value=""
		if($(from+'_adresse1') && $(to+'_adresse1'))			$(to+'_adresse1').value=$F(from+'_adresse1')
		if($(to+'_adresse2')) $(to+'_adresse2').value=""
		if($(from+'_adresse2') && $(to+'_adresse2'))			$(to+'_adresse2').value=$F(from+'_adresse2')
		if($(to+'_telefon')) $(to+'_telefon').value=""
		if($(from+'_telefon') && $(to+'_telefon'))				$(to+'_telefon').value=$F(from+'_telefon')
		if($(to+'_telefax')) $(to+'_telefax').value=""
		if($(from+'_telefax') && $(to+'_telefax'))				$(to+'_telefax').value=$F(from+'_telefax')
		if($(to+'_plz')) $(to+'_plz').value=""
		if($(from+'_plz') && $(to+'_plz'))						$(to+'_plz').value=$F(from+'_plz')
		if(to!='org') {
			if($(to+'_ort')) $(to+'_ort').value=""
			if($(from+'_ort') && $(to+'_ort'))						$(to+'_ort').value=$F(from+'_ort')
			if($(to+'_region')) $(to+'_region').value=""
			if($(from+'_region') && $(to+'_region'))				$(to+'_region').value=$F(from+'_region')
			if($(to+'_land')) $(to+'_land').value=""
			if($(from+'_land') && $(to+'_land'))					$(to+'_land').value=$F(from+'_land')
		}
	},

	/* Preis aktualisieren
	*/
	calculate_on_update: function() {

		var lizenzen = 1
		if($('lizenzen')) lizenzen = $F('lizenzen')		
		var sancount = 0
		if($('sancount')) sancount = $F('sancount')

		payload_out = {
				'ACTION': 				'request_quote',
				'TAX':					this.tax,
				'PRODUCT_CODE': 		this.product_code,
				'VALIDITY_PERIOD':		this.get_radio_value('laufzeit')*12,
				'SERVER_COUNT':			lizenzen,
				'SAN_COUNT':			sancount
		}

		new Ajax.Request(document.location+'', {
			method: 'POST',
			onSuccess: function(x) { 
				if(!cc.check_signature(x)) {
					alert("Der PHP-Code für den SSL-Wizard wurde nicht korrekt auf der Website eingebunden oder es ist ein PHP-Fehler aufgetreten. "+x.responseText)
					return false;
				}
				x = cc.xcrop(x)
				cc.debug("Gesamtpreis wurde neu kalkuliert");
				payload_in = x.responseText.evalJSON()
				if(payload_in['STATUS']=='success'||payload_in['STATUS']=='failure_callback') {
					var olp = $('livePrice').innerHTML
					$('livePrice').update(payload_in['PRICE_STR'])
					if($('livePrice').innerHTML!=olp) {
						var c = '#efefef'
						new Effect.Highlight('livePriceC', { startcolor: c, endcolor: '#ffff99', restorecolor: c })
					}
				} else if(payload_in['STATUS']=='failure') {
					alert('Ein unbekannter Fehler ist aufgetreten. Bitte kontaktieren Sie unseren Kundenservice.')
				} else if(payload_in['STATUS']=='failure_product') {
					cc.html(payload_in['ERROR_MSG']);
				} else if(payload_in['STATUS']=='failure_callback') {
					alert(payload_in['ERROR_MSG'])
					$('step_next').disabled=true
					$('step_prev').disabled=true
				}
				else {
					if(cc.current_step<cc.max_steps)
						$('step_next').disabled=false
					if(cc.current_step>1)
						$('step_prev').disabled=false
				}
 			},
			onFailure: function(x) { cc.debug("Gesamtpreis konnte nicht neu kalkuliert werden.") },
			parameters: { 'PAYLOAD': Object.toJSON(payload_out) }
		})
	},

	update_sancount: function() {
		if($('sancount')) {

			var sancount = $F('sancount');
			var san_package_count = $('san_package_count')?$F('san_package_count'):0;
			$('san_pos').show();
			if(sancount==1)
				$('sancountspan').update("Bitte geben Sie im Folgenden den von Ihnen gewünschten SAN-Host ein:")
			else
				$('sancountspan').update("Bitte geben Sie im Folgenden die <strong>"+$F('sancount')+"</strong> von Ihnen gewünschten SAN-Hosts ein:")

			var hostname = ""
			var hosts=Array();
			var i=0;
			$$('.SANhosts').each(function(e){
				hosts[i++]=e.value
			});
			$('sans').update("");
			for(var i=0;i<sancount;i++) {
				var div=new Element('div', {'style':'margin:2px'})
				div.update("<strong>"+(i+1)+". Hostname:</strong> ")
				if(hosts[i]) hostname=hosts[i]
				else hostname=""
				div.appendChild(new Element('input', {'id':'mysan'+i,'className':'SANhosts','value':hostname,'size':40,'maxlength':255}))
				$('sans').appendChild(div)
			}
			if(sancount<san_package_count) {
				var diff=san_package_count-sancount
				var div=new Element('div', {'style':'margin:2px;margin-top:10px;padding:4px;color:green;','className':'re'})
				if(diff>1)
					div.update("Noch "+diff+" weitere SAN-Hosts können ohne zusätzliche Mehrkosten hinzugefügt werden.")
				else if(diff==1)
					div.update("Noch "+diff+" weiterer SAN-Host kann ohne zusätzliche Mehrkosten hinzugefügt werden.")
				$('sans').appendChild(div)
			}
			this.calculate_on_update()

			if(sancount==0) {
				$('san_pos').hide()
			}
		}
	},
	
	/* Laedt das Basislayout
	*/
	load_layout: function() {
		step = 1
		this.debug("Schritt "+step+" wird mit ProdutCode "+this.product_code+" geladen.")
		payload_out = {
				'ACTION': 		'load_step',
				'STEP': 		step,
				'PRODUCT_CODE': this.product_code,
				'TAX':			this.tax
		}
		new Ajax.Request(document.location+'', {
			method: 'POST',
			onSuccess: function(x) {
				if(!cc.check_signature(x)) {
					cc.html('<span style="color:red;font-size:0.9em;">SSL-Wizard konnte nicht initialisiert werden! Bitte wenden Sie sich an den Kundenservice.</span>');
					return false;
				}
				x = cc.xcrop(x)
				cc.debug("AJAX-Request erfolgreich!");
				payload_in = x.responseText.evalJSON()
				try {
					payload_in = x.responseText.evalJSON()
					cc.debug("Payload-Status: "+payload_in['STATUS'])
					if(payload_in['STATUS']=='success') {
						cc.html(payload_in['HTML'])
						cc.max_steps = payload_in['STEPS']
						cc.last_step = cc.current_step
						cc.current_step = step
						$('cc_step_'+step).show()
						$('laufzeit_2').checked = true;
						cc.calculate_on_update()
						cc.initialize_form()
					} else {
						cc.html("Der SSL-Wizard konnte nicht geladen werden. "+payload_in['ERROR_MSG'])
						cc.max_steps = 0
					}
					cc.debug("HTML-Output erfolgt")
					cc.toggle_nextprev()
					
				} catch(e) {
					cc.debug("Antwort enthält keine verwertbaren Daten "+e)
				}
			},
			onFailure: function(x) {
				cc.debug("AJAX-Request NICHT erfolgreich! "+x.responseText)
			},
			parameters: { 'PAYLOAD': Object.toJSON(payload_out) }
		})
		this.debug("Ajax-Request abgesetzt (load_step)")
	},

	initialize_form: function() {
		var isVeriSignOrder = $F('isVeriSignOrder')
		var isDomainValidatedOrder = $F('isDomainValidatedOrder')
		var ProductCode = $F('ProductCode')
		
		if(isVeriSignOrder=='0') {
			$$('.requiredForVeriSignOrders').each(function(x) { x.remove() })
		}

		if(isDomainValidatedOrder!='0') {
			$$('.notRequiredForDomainValidatedOrders').each(function(x) {
				if(ProductCode=='SSL123'&&x.id=='block_anschrift_unternehmen') {}
				else x.hide() 
			})
			$$('.notRequiredForDomainValidatedOrders').each(function(x) { x.remove() })
			if(ProductCode!='SSL123') {
				// Titel/Position wird nicht benoetigt.
				$$('.requiredForDVandSSL123').each(function(x) { x.remove() })
			}
		}
	}
})

Event.observe(window, 'load', function() {
	cc = new SSLWIZARD()
	cc.initialize()
})
