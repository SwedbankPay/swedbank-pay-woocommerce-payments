/* global wc_checkout_params */
jQuery( function( $ ) {
    'use strict';

    /**
     * Object to handle Trustly payment forms.
     */
    window.wc_sb_trustly = {
        xhr: false,
        gateway_id: 'payex_psp_trustly',
        key: 'is_swedbank_pay_trustly',
        culture: WC_Gateway_Swedbank_Pay_Trustly.culture,
        hostedView: 'trustly',
    };

    $.extend( window.wc_sb_trustly, window.wc_sb_seamless );

    window.wc_sb_trustly.init( $( "form.checkout, form#order_review, form#add_payment_method" ) );
} );
