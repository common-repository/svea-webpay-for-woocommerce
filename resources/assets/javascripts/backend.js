jQuery(document).ready(function ($) {
    $(document).on('click', '.svea-credit-items', function(e) {
        var selectedItems = $(".woocommerce_order_items tr[data-order_item_id] .check-column input[type='checkbox']:checked");

        if(selectedItems.length === 0) {
            alert(Phrases.not_selected_any_items);
            return;
        }

        var orderItemIds = [];

        selectedItems.each(function() {
            orderItemIds.push($(this).parents("tr[data-order_item_id]").data('order_item_id'));
        });

        var doCreditItems = confirm(Phrases.confirm_credit_items.replace(/%d/g, orderItemIds.length));

        if(!doCreditItems)
            return;

        window.location.href = Svea.adminCreditUrl + orderItemIds.join(',');
    });

    $(document).on('click', '.svea-deliver-items', function(e) {
        var selectedItems = $(".woocommerce_order_items tr[data-order_item_id] .check-column input[type='checkbox']:checked");

        if(selectedItems.length === 0) {
            alert(Phrases.not_selected_any_items);
            return;
        }

        var orderItemIds = [];

        selectedItems.each(function() {
            orderItemIds.push($(this).parents("tr[data-order_item_id]").data('order_item_id'));
        });

        var doCreditItems = confirm(Phrases.confirm_deliver_items.replace(/%d/g, orderItemIds.length));

        if(!doCreditItems)
            return;

        window.location.href = Svea.adminDeliverUrl + orderItemIds.join(',');
    });

    if($("body").hasClass("post-type-shop_subscription")) {
        var companyAddresses = [];
        var selectedCompanyAddress = false;

        var gettingAddress = false;

        var PaymentMethod = {
            "INVOICE": "sveawebpay_invoice",
            "PART_PAY": "sveawebpay_part_pay",
            "CARD": "sveawebpay_card",
            "DIRECT_BANK": "sveawebpay_direct_bank"
        };

        var checkoutCountry = $('.woocommerce select[name="_billing_country"]').val().toLowerCase();
        var paymentMethod = $('.woocommerce select[name="_payment_method"]').val();
        var customerType = $('.woocommerce select[name="_iv_billing_customer_type"]').val().toLowerCase();

        if(Svea.onlyOneAllowedCountry != false) {
            checkoutCountry = Svea.onlyOneAllowedCountry.toLowerCase();
        }

        if(typeof checkoutCountry !== "undefined" && checkoutCountry != false && checkoutCountry.length > 0)
            $(".svea-fields").addClass("country-" + checkoutCountry);

        if(typeof paymentMethod !== "undefined" && paymentMethod != false && paymentMethod.length > 0)
            $(".svea-fields").addClass("payment-method-" + paymentMethod);

        if(typeof customerType !== "undefined" && customerType != false && customerType.length > 0)
            $(".svea-fields").addClass("customer-type-" + customerType);

        var getInformationOptions = function(customerIdentities) {
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

        var setCustomerInformation = function(customerIdentity) {
            if(String(customerIdentity.firstName).length > 0)
                $("#_billing_first_name").val(String(customerIdentity.firstName));
            if(String(customerIdentity.lastName).length > 0)
                $("#_billing_last_name").val(String(customerIdentity.lastName));

            if(String(customerIdentity.customerType).toLowerCase() == "business")
                $("#_billing_company").val(String(customerIdentity.fullName));

            if(String(customerIdentity.street).length > 0)
                $("#_billing_address_1").val(String(customerIdentity.street));
            if(String(customerIdentity.coAddress).length > 0)
                $("#_billing_address_2").val(String(customerIdentity.coAddress));
            if(String(customerIdentity.zipCode).length > 0)
                $("#_billing_postcode").val(String(customerIdentity.zipCode));
            if(String(customerIdentity.locality).length > 0)
                $("#_billing_city").val(String(customerIdentity.locality));
            if(String(customerIdentity.phoneNumber).length > 0)
                $("#_billing_phone").val(String(customerIdentity.phoneNumber));
        }

        $(document).on('click', '.svea-get-address-button', function (e) {
            var parentElement = $(this).parents(".svea-fields-admin");

            var getAddressContainer = parentElement.find('.svea-get-address-button-container');

            e.preventDefault();

            if(gettingAddress)
                return;

            if(customerType == 'individual' || paymentMethod == PaymentMethod.PART_PAY) {
                var methodPrefix;

                if(paymentMethod == PaymentMethod.INVOICE)
                    methodPrefix = "iv";
                else
                    methodPrefix = "pp";

                var personalNumber = parentElement.find("input[name='_"+methodPrefix+"_billing_ssn']").val();

                parentElement.addClass("getting-address");
                gettingAddress = true;

                $.post(Svea.ajaxUrl, {
                    "action": "svea_get_address",
                    "pers_nr": personalNumber,
                    "country_code": checkoutCountry,
                    "payment_type": paymentMethod,
                    "security": Svea.gaSecurity
                }, function (response) {
                    parentElement.removeClass("getting-address");

                    gettingAddress = false;

                    if(typeof response.resultcode === "undefined"
                        || typeof response.customerIdentity === "undefined") {
                        getAddressContainer.after('<div class="svea-message error">'+Phrases.could_not_get_address+'</div>');
                        parentElement.addClass("getting-address-error");
                        setTimeout(function() { 
                            parentElement.find(".svea-message.error").remove();
                            parentElement.removeClass("getting-address-error");
                        }, 5000);
                        return;
                    }

                    if(response.resultcode == 'Error' || response.resultcode == 'NoSuchEntity') {
                        getAddressContainer.after('<div class="svea-message error">'+response.errormessage+'</div>');
                        parentElement.addClass("getting-address-error");
                        setTimeout(function() { 
                            parentElement.find(".svea-message.error").remove();
                            parentElement.removeClass("getting-address-error");
                        }, 5000);
                        return;
                    }

                    var customerIdentity = response.customerIdentity[0];

                    setCustomerInformation(customerIdentity);

                    getAddressContainer.after('<div class="svea-message success">' + Phrases.your_address_was_found + '</div>');
                    parentElement.addClass("getting-address-success");
                    setTimeout(function() { 
                        parentElement.find(".svea-message.success").remove();
                        parentElement.removeClass("getting-address-success");
                    }, 5000);
                });
            } else if(customerType == 'company') {
                var methodPrefix;

                if(paymentMethod == PaymentMethod.INVOICE)
                    methodPrefix = "iv";
                else
                    methodPrefix = "pp";

                var organisationNumber = parentElement.find("input[name='_"+methodPrefix+"_billing_org_number']").val();

                parentElement.addClass("getting-address");
                gettingAddress = true;
                
                $.post(Svea.ajaxUrl, { 
                    "action": "svea_get_address", 
                    "org_nr": organisationNumber, 
                    "country_code": checkoutCountry, 
                    "payment_type": paymentMethod,
                    "security": Svea.gaSecurity
                }, function (response) {
                    parentElement.removeClass("getting-address");

                    gettingAddress = false;

                    if(typeof response.resultcode === "undefined"
                        || typeof response.customerIdentity === "undefined") {
                        getAddressContainer.after('<div class="svea-message error">'+Phrases.could_not_get_address+'</div>');
                        parentElement.addClass("getting-address-error");
                        setTimeout(function() { 
                            parentElement.find(".svea-message.error").remove();
                            parentElement.removeClass("getting-address-error");
                        }, 5000);
                        return;
                    }
                
                    if(response.resultcode == 'Error' || response.resultcode == 'NoSuchEntity') {
                        getAddressContainer.after('<div class="svea-message error">'+response.errormessage+'</div>');
                        parentElement.addClass("getting-address-error");
                        setTimeout(function() { 
                            parentElement.find(".svea-message.error").remove();
                            parentElement.removeClass("getting-address-error");
                        }, 5000);
                        return;
                    }

                    var customerIdentity = response.customerIdentity;

                    $(".org-address-selector").html(getInformationOptions(customerIdentity));
                    companyAddresses = customerIdentity;

                    $(".address-selector").val(customerIdentity[0].addressSelector);

                    setCustomerInformation(customerIdentity[0]);

                    parentElement.addClass("getting-address-success");
                    getAddressContainer.after('<div class="svea-message success">' + Phrases.your_address_was_found + '</div>');
                    setTimeout(function() { 
                        parentElement.find(".svea-message.success").remove(); 
                        parentElement.removeClass("getting-address-success");
                    }, 5000);
                });
            }
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

        $(document).on('click', '.woocommerce a.edit_address', function (e) {
            $(".svea-fields").addClass("edit-opened");
        });

        $(document).on('change', '.woocommerce select[name="_billing_country"]', function (e) {
            $(".svea-fields").removeClass("country-" + checkoutCountry);
            checkoutCountry = $(this).val().toLowerCase();
            $(".svea-fields").addClass("country-" + checkoutCountry);
        });

        $(document).on('change', '.woocommerce select[name="_payment_method"]', function (e) {
            $(".svea-fields").removeClass("payment-method-" + paymentMethod);
            paymentMethod = $(this).val();
            $(".svea-fields").addClass("payment-method-" + paymentMethod);
        });

        $(document).on('change', '.woocommerce select[name="_iv_billing_customer_type"]', function (e) {
            $(".svea-fields").removeClass("customer-type-" + customerType);
            customerType = $(this).val();
            $(".svea-fields").addClass("customer-type-" + customerType);
        });
    }
});