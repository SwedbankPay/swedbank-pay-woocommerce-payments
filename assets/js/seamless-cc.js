/* global wc_checkout_params */
jQuery( function( $ ) {
    'use strict';

    /**
     * Object to handle Cc payment forms.
     */
    window.wc_sb_cc = {
        xhr: false,
        gateway_id: 'payex_psp_cc',
        key: 'is_swedbank_pay_cc',
        culture: WC_Gateway_Swedbank_Pay_Cc.culture,
        hostedView: 'creditCard',
    };

    $.extend( window.wc_sb_cc, window.wc_sb_seamless );

    window.wc_sb_cc.init( $( "form.checkout, form#order_review, form#add_payment_method" ) );
} );
