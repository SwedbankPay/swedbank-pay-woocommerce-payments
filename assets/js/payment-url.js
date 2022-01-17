/* global wc_checkout_params */
jQuery( function( $ ) {
    'use strict';

    window.wc_sb_payments_payment_url = {
        /**
         * Initiate Payment Javascript
         * @param url
         * @param callback
         */
        initPaymentJS: function ( url, callback ) {
            if ( typeof callback === 'undefined' ) {
                callback = function () {};
            }

            // Load JS
            var self = this;
            self.hostedView = WC_Sb_Payments_Payment_Url.hostedView;
            self.culture = WC_Sb_Payments_Payment_Url.culture;
            this.loadJs( url, function () {
                $( '#payment-swedbank-pay-payments iframe' ).remove();

                // Initiate the payment menu
                $( '#payment' ).hide();
                self.initPaymentMenu( 'payment-swedbank-pay-payments' );

                callback()
            } );
        },
    }

    $.extend( window.wc_sb_payments_payment_url, window.wc_sb_seamless );

    $(document).ready( function () {
        wc_sb_payments_payment_url.initPaymentJS( WC_Sb_Payments_Payment_Url.payment_url, function () {
            console.log( 'Payment url has been loaded.' );
        } );
    } );
});
