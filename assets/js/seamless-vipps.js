/* global wc_checkout_params */
jQuery( function( $ ) {
    'use strict';

    /**
     * Object to handle Vipps payment forms.
     */
    window.wc_sb_vipps = {
        xhr: false,
        gateway_id: 'payex_psp_vipps',
        key: 'is_swedbank_pay_vipps',
        culture: WC_Gateway_Swedbank_Pay_Vipps.culture,
        hostedView: 'vipps',
    };

    $.extend( window.wc_sb_vipps, window.wc_sb_seamless );

    window.wc_sb_vipps.init( $( "form.checkout, form#order_review, form#add_payment_method" ) );
} );
