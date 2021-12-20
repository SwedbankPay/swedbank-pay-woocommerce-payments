/* global wc_checkout_params */
jQuery( function( $ ) {
    'use strict';

    /**
     * Object to handle Swish payment forms.
     */
    window.wc_sb_swish = {
        xhr: false,
        gateway_id: 'payex_psp_swish',
        key: 'is_swedbank_pay_swish',
        culture: WC_Gateway_Swedbank_Pay_Swish.culture,
        hostedView: 'swish',
    };

    $.extend( window.wc_sb_swish, window.wc_sb_seamless );

    window.wc_sb_swish.init( $( "form.checkout, form#order_review, form#add_payment_method" ) );
} );
