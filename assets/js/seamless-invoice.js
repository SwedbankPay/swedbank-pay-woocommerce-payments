/* global wc_checkout_params */
jQuery( function( $ ) {
    'use strict';

    /**
     * Object to handle Invoice payment forms.
     */
    window.wc_sb_invoice = {
        xhr: false,
        gateway_id: 'payex_psp_invoice',
        key: 'is_swedbank_pay_invoice',
        culture: WC_Gateway_Swedbank_Pay_Invoice.culture,
        hostedView: 'invoice',
    };

    $.extend( window.wc_sb_invoice, window.wc_sb_seamless );

    window.wc_sb_invoice.init( $( "form.checkout, form#order_review, form#add_payment_method" ) );
} );
