/* global wc_checkout_params */
jQuery( function( $ ) {
    'use strict';

    /**
     * Object to handle Mobilepay payment forms.
     */
    window.wc_sb_mobilepay = {
        xhr: false,
        gateway_id: 'payex_psp_mobilepay',
        key: 'is_swedbank_pay_mobilepay',
        culture: WC_Gateway_Swedbank_Pay_Mobilepay.culture,
        hostedView: 'mobilepay',
    };

    $.extend( window.wc_sb_mobilepay, window.wc_sb_seamless );

    window.wc_sb_mobilepay.init( $( "form.checkout, form#order_review, form#add_payment_method" ) );
} );
