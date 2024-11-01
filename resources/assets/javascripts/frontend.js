jQuery(document).ready(function ($) {

	var companyAddresses = [];
	var selectedCompanyAddress = false;

	var addressFetchedWithGetAddress = false;

	var gettingAddress = false;

	var PaymentMethod = {
		"INVOICE": "sveawebpay_invoice",
		"PART_PAY": "sveawebpay_part_pay",
		"CARD": "sveawebpay_card",
		"DIRECT_BANK": "sveawebpay_direct_bank"
	};

	var paymentMethod = $('[name="payment_method"]:checked').val() || false;

	var checkoutCountry = $('[name="billing_country"]').val() || false;

	if( typeof Svea.sameShippingAsBilling[paymentMethod] !== "undefined" && Svea.sameShippingAsBilling[paymentMethod] ) {
		$(".woocommerce form.woocommerce-checkout").addClass("hide-shipping-fields");
	}

	if(Svea.onlyOneAllowedCountry != false) {
		checkoutCountry = Svea.onlyOneAllowedCountry;
	}

	if(Svea.isPayPage) {
		checkoutCountry = Svea.customerCountry;

		var oldPaymentMethod = paymentMethod || false;

		$(".payment_methods, .svea-get-address-button-container").addClass("is-pay-page");
	}

	$(".payment_methods, .svea-get-address-button-container").addClass("payment-method-" + paymentMethod).addClass("country-" + ( checkoutCountry ? checkoutCountry.toLowerCase() : "false" ) );

	var sveaCustomerType = false;

	/**
	 * If the get-address shortcode is used, add essential classes and set values
	 */
	if($(".svea-get-address-button-container.get-address-shortcode").length > 0) {
		if($(".payment_method_" + PaymentMethod.INVOICE).is(":visible")) {
			$(".svea-get-address-button-container.get-address-shortcode").removeClass("invoice-not-available");
		} else {
			$(".svea-get-address-button-container.get-address-shortcode").addClass("invoice-not-available");
		}
	}

	if($(".svea-get-address-button-container.get-address-shortcode").is(":visible")) {
		sveaCustomerType = $("[name='svea_get_address_customer_type']:checked").val();
		$('[name="iv_billing_customer_type"]').val(sveaCustomerType);
		$(".payment_methods, .svea-get-address-button-container").addClass("customer-type-" + sveaCustomerType);
	}

	if( ! shouldShowFields(checkoutCountry, paymentMethod, sveaCustomerType) ) {
		showFields(false);
		if(!addressFetchedWithGetAddress) {
			clearWCFields();
		}
	} else if( shouldShowFields(checkoutCountry, paymentMethod, sveaCustomerType) ) {
		showFields(true);
	}

	function showElementAndFadeOut(element, wait, fade, complete, customDisplay) {
		if(typeof customDisplay!='undefined')
			element.css("display", customDisplay);
		else
			element.show();

		setTimeout(function() {
			element.animate({
				opacity: 0
			}, fade, function() {
				element.hide();
				element.css("opacity", "1");
				if(complete!=null)
					complete.call();
			});
		}, wait);
	}

	function getFirstElement(selector) {
		if(typeof selector == 'undefined')
			return false;
		selector.each(function() {
			return $(this);
		});
	}

	function getInformationOptions(customerIdentities) {
		if(customerIdentities.length <= 0)
			return "";

		var options = "";

		for(var i=0;i<customerIdentities.length;++i) {
			var ci = customerIdentities[i];

			options += '<option value="' + ci.addressSelector + '">';

			if(ci.customerType.toLowerCase() == "business") {
				options += ci.fullName + ', ';
			} else if(ci.customerType.toLowerCase() == "person") {
				options += ci.lastName + ', '
						+ ci.firstName + ' ';
			}

			options += ci.street + ' '
					+ ci.zipCode + ' '
					+ ci.locality
					+ '</option>';
		}

		return options;
	}

	function setCustomerInformation(customerIdentity) {
		if(String(customerIdentity.firstName).length > 0) {
            $("#billing_first_name").val(String(customerIdentity.firstName)).change().trigger('keydown');
        }

		if(String(customerIdentity.lastName).length > 0) {
            $("#billing_last_name").val(String(customerIdentity.lastName)).change().trigger('keydown');
        }

		if(String(customerIdentity.customerType).toLowerCase() == "business") {
            $("#billing_company").val(String(customerIdentity.fullName)).change().trigger('keydown');
        }

		if(String(customerIdentity.street).length > 0) {
            $("#billing_address_1").val(String(customerIdentity.street)).change().trigger('keydown');
        }

		if(String(customerIdentity.coAddress).length > 0) {
            $("#billing_address_2").val(String(customerIdentity.coAddress)).change().trigger('keydown');
        }

		if(String(customerIdentity.zipCode).length > 0) {
            $("#billing_postcode").val(String(customerIdentity.zipCode)).change().trigger('keydown');
        }

		if(String(customerIdentity.locality).length > 0) {
            $("#billing_city").val(String(customerIdentity.locality)).change().trigger('keydown');
        }

		if(String(customerIdentity.phoneNumber).length > 0) {
            $("#billing_phone").val(String(customerIdentity.phoneNumber)).change().trigger('keydown');
        }

		addressFetchedWithGetAddress = true;
	}

	function showFields(show) {
		if(show) {
			//Remove the read-only protection to make the fields editable once again
			$("#billing_address_1, #billing_address_2, #billing_postcode, \
				#billing_city").prop("readonly", false);

			//Remove the class so the fields look like they normally do
			$("#billing_address_1, #billing_address_2, #billing_postcode, \
				#billing_city").removeClass("disabled-input");
		} else {
			//Make the fields read-only so they are not editable
			$("#billing_address_1, #billing_address_2, \
				#billing_postcode, #billing_city").prop("readonly", true);

			//Add the class for custom styling of the disabled fields
			$("#billing_address_1, #billing_address_2, \
				#billing_postcode, #billing_city").addClass("disabled-input");
		}
	}

	$(document).on("change", ".woocommerce form.woocommerce-checkout .woocommerce-billing-fields :input", function (e) {
		addressFetchedWithGetAddress = false;
	});

	$(document).on('keydown', '[name="svea_billing_ssn"], [name="svea_billing_org_number"], [name="iv_billing_org_number"], [name="iv_billing_ssn"], [name="pp_billing_ssn"]', function(e) {
		var $input = $(e.target);

		var key = e.which || e.keyCode || 0;

		//Check if the button was enter
		if(key == 13) {
			e.preventDefault();

			$container = $input.closest('.svea-fields, .svea-get-address-button-container');

			var $getAddressButton = $container.find('.svea-get-address-button');

			if($getAddressButton.is(":visible")) {
				$getAddressButton.click();
			}
		}
	});

	$(document).on('change', '[name="svea_billing_ssn"]', function (e) {
		$("[name='iv_billing_ssn'], [name='pp_billing_ssn']").val($(this).val());
	});

	$(document).on('change', '[name="svea_billing_org_number"]', function (e) {
		$("[name='iv_billing_org_number'], [name='pp_billing_org_number']").val($(this).val());
	});

	$(document).on('click', '.svea-get-address-button', function (e) {
		e.preventDefault();

		var currentPaymentMethod = paymentMethod;

		var addressBtn = $(this);

		var getAddressContainer = $(this).closest('.svea-get-address-button-container');

		var getAddressIsShortcode = $(".svea-get-address-button-container.get-address-shortcode").is(":visible");

		var sveaFieldContainers = $(".svea-invoice-fields, .svea-part-pay-fields");

		if(gettingAddress)
			return;

		if(getAddressIsShortcode) {
			currentPaymentMethod = PaymentMethod.INVOICE;
		}

		if(sveaCustomerType == 'individual' || currentPaymentMethod == PaymentMethod.PART_PAY) {
			var personalNumber;

			if(getAddressIsShortcode) {
				personalNumber = $("[name='svea_billing_ssn']").val();
			} else {
				var methodPrefix;

				if(currentPaymentMethod == PaymentMethod.INVOICE)
					methodPrefix = "iv";
				else
					methodPrefix = "pp";

				personalNumber = $("[name='"+methodPrefix+"_billing_ssn']").val();
			}

			getAddressContainer.addClass("getting-address");
			gettingAddress = true;

			$.post(Svea.ajaxUrl, {
				"action": "svea_get_address",
				"pers_nr": personalNumber,
				"country_code": checkoutCountry,
				"payment_type": currentPaymentMethod,
				"security": Svea.gaSecurity
			}, function (response) {
				getAddressContainer.removeClass("getting-address");

				gettingAddress = false;

				if(typeof response.resultcode === "undefined"
					|| typeof response.customerIdentity === "undefined") {
					getAddressContainer.append('<div class="svea-message error">'+Phrases.could_not_get_address+'</div>');
					getAddressContainer.addClass("getting-address-error");
					setTimeout(function() { 
						getAddressContainer.find(".svea-message.error").remove();
						getAddressContainer.removeClass("getting-address-error");
					}, 5000);
					return;
				}

				if(response.resultcode == 'Error' || response.resultcode == 'NoSuchEntity') {
					getAddressContainer.append('<div class="svea-message error">'+response.errormessage+'</div>');
					getAddressContainer.addClass("getting-address-error");
					setTimeout(function() { 
						getAddressContainer.find(".svea-message.error").remove();
						getAddressContainer.removeClass("getting-address-error");
					}, 5000);
					return;
				}

				var customerIdentity = response.customerIdentity[0];

				setCustomerInformation(customerIdentity);

				getAddressContainer.append('<div class="svea-message success">' + Phrases.your_address_was_found + '</div>');
				getAddressContainer.addClass("getting-address-success");
				setTimeout(function() { 
					getAddressContainer.find(".svea-message.success").remove();
					getAddressContainer.removeClass("getting-address-success");
				}, 5000);
			});
		} else if(sveaCustomerType == 'company') {

			var organisationNumber;

			if(getAddressIsShortcode) {
				organisationNumber = $("[name='svea_billing_org_number']").val();
			} else {
				var methodPrefix;

				if(currentPaymentMethod == PaymentMethod.INVOICE)
					methodPrefix = "iv";
				else
					methodPrefix = "pp";

				organisationNumber = $("[name='"+methodPrefix+"_billing_org_number']").val();
			}

			getAddressContainer.addClass("getting-address");
			gettingAddress = true;

			$.post(Svea.ajaxUrl, { 
				"action": "svea_get_address", 
				"org_nr": organisationNumber, 
				"country_code": checkoutCountry, 
				"payment_type": currentPaymentMethod,
				"security": Svea.gaSecurity
			}, function (response) {
				getAddressContainer.removeClass("getting-address");

				gettingAddress = false;

				if(typeof response.resultcode === "undefined"
					|| typeof response.customerIdentity === "undefined") {
					getAddressContainer.append('<div class="svea-message error">'+Phrases.could_not_get_address+'</div>');
					getAddressContainer.addClass("getting-address-error");
					setTimeout(function() { 
						getAddressContainer.find(".svea-message.error").remove();
						getAddressContainer.removeClass("getting-address-error");
					}, 5000);
					return;
				}
			
				if(response.resultcode == 'Error' || response.resultcode == 'NoSuchEntity') {
					getAddressContainer.append('<div class="svea-message error">'+response.errormessage+'</div>');
					getAddressContainer.addClass("getting-address-error");
					setTimeout(function() { 
						getAddressContainer.find(".svea-message.error").remove();
						getAddressContainer.removeClass("getting-address-error");
					}, 5000);
					return;
				}

				var customerIdentity = response.customerIdentity;

				$(".org-address-selector").html(getInformationOptions(customerIdentity));
				companyAddresses = customerIdentity;

				$(".address-selector").val(customerIdentity[0].addressSelector);

				setCustomerInformation(customerIdentity[0]);

				selectedCompanyAddress = customerIdentity[0];

				getAddressContainer.addClass("getting-address-success");
				getAddressContainer.append('<div class="svea-message success">' + Phrases.your_address_was_found + '</div>');
				setTimeout(function() { 
					getAddressContainer.find(".svea-message.success").remove(); 
					getAddressContainer.removeClass("getting-address-success");
				}, 5000);
			});
		}
	});

	if(Svea.isPayPage) {
		$('.payment_methods [name="payment_method"]').on('change', function () {
	 		var oldPaymentMethod = paymentMethod || false;
	 		var oldCustomerType = sveaCustomerType || false;

	 		paymentMethod = $('[name="payment_method"]:checked').val();
	 		sveaCustomerType = $('[name="iv_billing_customer_type"]').val() || false;

	 		$(".payment_methods, .svea-get-address-button-container")
	 			.removeClass("payment-method-" + oldPaymentMethod)
				.addClass("payment-method-" + paymentMethod)
				.removeClass("customer-type-" + sveaCustomerType)
				.addClass("customer-type-" + sveaCustomerType);

			selectedCompanyAddress = false;
			companyAddresses = [];
			$(".org-address-selector").html("");
		});
	}

 	$( document ).on('updated_checkout', function() {
 		if($(".svea-get-address-button-container.get-address-shortcode").length > 0) {
 			if($(".payment_method_" + PaymentMethod.INVOICE).is(":visible")) {
 				$(".svea-get-address-button-container.get-address-shortcode").removeClass("invoice-not-available");
 			} else {
 				$(".svea-get-address-button-container.get-address-shortcode").addClass("invoice-not-available");
 			}
 		}

 		 // Check if the get address shortcode is included
 		if($(".svea-get-address-button-container.get-address-shortcode").is(":visible")) {
 			$("[name='iv_billing_ssn'], [name='pp_billing_ssn']").val($("[name='svea_billing_ssn']").val());
 			$("[name='iv_billing_org_number']").val($("[name='svea_billing_org_number']").val());
 			$('[name="iv_billing_customer_type"]').val($('[name="svea_get_address_customer_type"]:checked').val());
 		}

 		var oldCountry = checkoutCountry || false;
 		var oldPaymentMethod = paymentMethod || false;
 		var oldSveaCustomerType = sveaCustomerType || false;

 		if(Svea.onlyOneAllowedCountry == false)
 			checkoutCountry = $('[name="billing_country"]').val();

 		paymentMethod = $('[name="payment_method"]:checked').val();
 		sveaCustomerType = $('[name="iv_billing_customer_type"]').val() || false;
 		
 		$(".payment_methods, .svea-get-address-button-container")
 						.removeClass("country-" + ( oldCountry ? oldCountry.toLowerCase() : "false" ) )
						 .addClass("country-" + ( checkoutCountry ? checkoutCountry.toLowerCase() : "false" ) )
						 .removeClass("payment-method-" + oldPaymentMethod)
						 .addClass("payment-method-" + paymentMethod);

		if($(".svea-get-address-button-container.get-address-shortcode").is(":visible")) {
 			sveaCustomerType = $('[name="iv_billing_customer_type"]').val() || false;
 		}

 		$(".payment_methods, .svea-get-address-button-container")
 						.removeClass("customer-type-" + oldSveaCustomerType)
						 .addClass("customer-type-" + sveaCustomerType);

		if(selectedCompanyAddress !== false
			&& companyAddresses.length > 0) {
			$(".org-address-selector").html(getInformationOptions(companyAddresses));
			$(".org-address-selector").val(selectedCompanyAddress.addressSelector);
			$(".address-selector").val(selectedCompanyAddress.addressSelector);
		}

 		if( typeof Svea.sameShippingAsBilling[paymentMethod] !== "undefined" 
 			&& Svea.sameShippingAsBilling[paymentMethod]) {
 			if( ! $(".woocommerce .woocommerce-checkout").hasClass("hide-shipping-fields") )
 				$(".woocommerce .woocommerce-checkout").addClass("hide-shipping-fields");
 		} else {
 			if( $(".woocommerce .woocommerce-checkout").hasClass("hide-shipping-fields") )
 				$(".woocommerce .woocommerce-checkout").removeClass("hide-shipping-fields");
 		}

		if(oldCountry !== checkoutCountry
			|| paymentMethod !== oldPaymentMethod) {
			
			selectedCompanyAddress = false;
			companyAddresses = [];
			$(".org-address-selector").html("");
			// $(".payment_methods, .svea-get-address-button-container").removeClass("customer-type-" + sveaCustomerType);

	 	// 	var oldCustomerType = sveaCustomerType;
			// sveaCustomerType = false;

			if( ! shouldShowFields(checkoutCountry, paymentMethod, sveaCustomerType) ) {
				showFields(false);
				if(!addressFetchedWithGetAddress) {
					clearWCFields();
				}
			} else if( shouldShowFields(checkoutCountry, paymentMethod, sveaCustomerType) ) {
				showFields(true);
			}
		}		
 	});

	function shouldShowFields(country, paymentMethod, customerType) {
		switch(country) {
			case "SE":
			case "DK":
				switch(paymentMethod) {
					case PaymentMethod.INVOICE:
						if(customerType === "individual") {
							return false;
						}
					break;

					case PaymentMethod.PART_PAY:
						return false;
				}
			break;
			// case "NO":
			// 	switch(paymentMethod) {
			// 		case PaymentMethod.INVOICE:
			// 			if(typeof customerType !== "undefined" 
			// 				&& customerType == 'company')
			// 				return false;
			// 		break;
			// 	}
			// break;
		}
		return true;
	}

	function showFields(show) {
		if(show) {
			//Remove the read-only protection to make the fields editable once again
			$("#billing_first_name, #billing_last_name, #billing_address_1, \
				#billing_address_2, #billing_postcode, #billing_city").prop("readonly", false);

			//Remove the class so the fields look like they normally do
			$("#billing_first_name, #billing_last_name, #billing_address_1, \
				#billing_address_2, #billing_postcode, #billing_city").removeClass("disabled-input");
		} else {
			//Make the fields read-only so they are not editable
			$("#billing_first_name, #billing_last_name, #billing_address_1, \
				#billing_address_2, #billing_postcode, #billing_city").prop("readonly", true);

			//Add the class for custom styling of the disabled fields
			$("#billing_first_name, #billing_last_name, #billing_address_1, \
				#billing_address_2, #billing_postcode, #billing_city").addClass("disabled-input");
		}
	}

	function clearWCFields() {
		$("#billing_first_name, #billing_last_name, #billing_address_1, \
				#billing_address_2, #billing_postcode, #billing_city").val([]);
	}

	function clearSveaFields() {
		$(".svea-invoice-fields .woocommerce-validated, .svea-part-pay-fields .woocommerce-validated").removeClass("woocommerce-validated");
		$(".svea-invoice-fields input, .svea-part-pay-fields input").val([]);
		$(".svea-invoice-fields select, .svea-part-pay-fields select").prop("selectedIndex", 0);
	}

	$(document).on('change', '[name="payment_method"]', function () {
		$('body').trigger('update_checkout');
	});

	$(document).on('change', '[name="billing_country"]', function() {
		var oldCountry = checkoutCountry || false;
 		checkoutCountry = $('[name="billing_country"]').val();
 		
 		$(".payment_methods, .svea-get-address-button-container")
			.removeClass("country-" + ( oldCountry ? oldCountry.toLowerCase() : "false" ) )
			.addClass("country-" + ( checkoutCountry ? checkoutCountry.toLowerCase() : "false" ) );

		if( ! shouldShowFields(checkoutCountry, paymentMethod, sveaCustomerType) ) {
			showFields(false);
			if(!addressFetchedWithGetAddress) {
				clearWCFields();
			}
		} else if( shouldShowFields(checkoutCountry, paymentMethod, sveaCustomerType) ) {
			showFields(true);
		}
	});

	var lastSel = null;

	$(document).on('change', '[name="iv_billing_customer_type"], [name="svea_get_address_customer_type"]', function () {
		if(gettingAddress) {
			if(lastSel!=null) {
				$(this).val(lastSel.val());
			}
			return;
		}

 		var oldCustomerType = sveaCustomerType || false;
		sveaCustomerType = $(this).val() || false;

		$(".payment_methods, .svea-get-address-button-container")
						.removeClass("customer-type-" + oldCustomerType)
					    .addClass("customer-type-" + sveaCustomerType);

		lastSel = $(this);

		if( ! shouldShowFields(checkoutCountry, paymentMethod, sveaCustomerType) ) {
			showFields(false);
			if(!addressFetchedWithGetAddress) {
				clearWCFields();
			}
		} else if( shouldShowFields(checkoutCountry, paymentMethod, sveaCustomerType) ) {
			showFields(true);
		}
	});

	$(document).on('change', '[name="svea_get_address_customer_type"]', function() {
		$("[name='iv_billing_customer_type']").val($(this).val());
		lastSel = $(this);
		$('body').trigger('update_checkout');
	});

	$(document).on('change', '.org-address-selector', function() {
		for(var i=0;i<companyAddresses.length;++i) {
			var coAddress = companyAddresses[i];
			if(coAddress.addressSelector == $(".org-address-selector").val()) {
				$(".address-selector").val($(".org-address-selector").val());
				setCustomerInformation(coAddress);
				selectedCompanyAddress = coAddress;
				return;
			}
		}
	});

	$(document).on('change', '.birth-date-month, .birth-date-day, .birth-date-year', function() {
		var parentElement = $(this).parents(".svea-part-pay-fields, .svea-invoice-fields");

		var year = parseInt(parentElement.find(".birth-date-year").val());
		var month = parseInt(parentElement.find(".birth-date-month").val());

		var day = parseInt(parentElement.find(".birth-date-day").val());

		var dd = new Date(year, month, 0);
		var daysInMonth = dd.getDate();
		
		parentElement.find(".birth-date-day option").each(function() {
			if(parseInt($(this).val())>daysInMonth)
				$(this).prop("disabled", true)
			else
				$(this).prop("disabled", false);
		});

		if(day>daysInMonth) {
			parentElement.find(".birth-date-day").val(daysInMonth);
		}
	});
});
