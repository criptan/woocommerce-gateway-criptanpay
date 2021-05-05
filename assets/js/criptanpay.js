
jQuery( function( $ ) {
	'use strict';

	var wc_criptanpay_gateway = {
        goCheckout: function() {
            window.location =  $('#criptanpay-gateway-redirect a.button').attr('href');
        },
		init: function() {
			if ( $('#criptanpay-gateway-redirect').length ) {
                var self = this;
                window.setTimeout(function(){
                    self.goCheckout();
                }, 3000 );
            } 
		}
	};

	wc_criptanpay_gateway.init();
} );