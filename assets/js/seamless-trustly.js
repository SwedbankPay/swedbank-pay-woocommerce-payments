/* global wc_checkout_params */
jQuery( function( $ ) {
    'use strict';

    $( document ).ajaxComplete( function ( event, xhr, settings ) {
        if ( ( settings.url === wc_checkout_params.checkout_url ) || ( settings.url.indexOf( 'wc-ajax=complete_order' ) > -1 ) ) {
            const data = xhr.responseText;

            // Parse
            try {
                const result = JSON.parse( data );

                // Check is response from payment gateway
                if ( ! result.hasOwnProperty( 'is_swedbank_pay_trustly' ) ) {
                    return false;
                }

                // Save js_url value
                wc_sb_trustly.setJsUrl( result.js_url );
            } catch ( e ) {
                return false;
            }
        }
    } );

    /**
     * Object to handle Trustly payment forms.
     */
    window.wc_sb_trustly = {
        xhr: false,

        /**
         * Initialize e handlers and UI state.
         */
        init: function( form ) {
            this.form         = form;
            this.form_submit  = false;
            this.js_url       = null;

            $( this.form )
                // We need to bind directly to the click (and not checkout_place_order_payex_checkout) to avoid popup blockers
                // especially on mobile devices (like on Chrome for iOS) from blocking payex_checkout(payment_id, {}, 'open'); from opening a tab
                .on( 'click', '#place_order', {'obj': window.wc_sb_trustly}, this.onSubmit )

                // WooCommerce lets us return a false on checkout_place_order_{gateway} to keep the form from submitting
                .on( 'submit checkout_place_order_payex_psp_trustly' );
        },

        /**
         * Initiate Payment Menu.
         * Payment Javascript must be loaded.
         *
         * @param id
         * @param callback
         */
        initPaymentMenu: function ( id, callback ) {
            console.log( 'initPaymentMenu' );

            if ( typeof callback === 'undefined' ) {
                callback = function () {};
            }

            // Load Trustly frame
            this.paymentMenu = window.payex.hostedView.trustly( {
                container: id,
                culture: WC_Gateway_Swedbank_Pay_Trustly.culture,
                onApplicationConfigured: function( data ) {
                    console.log( 'onApplicationConfigured' );
                    console.log( data );
                    callback( null );
                },
                onPaymentCreated: function () {
                    console.log( 'onPaymentCreated' );
                },
                onPaymentCompleted: function ( data ) {
                    console.log( 'onPaymentCompleted' );
                    console.log( data );

                    self.location.href = data.redirectUrl;
                },
                onPaymentCanceled: function ( data ) {
                    console.log( 'onPaymentCanceled' );
                    console.log( data );
                },
                onPaymentFailed: function ( data ) {
                    console.log( 'onPaymentFailed' );
                    console.log( data );

                    self.location.href = data.redirectUrl;
                },
                onError: function ( data ) {
                    console.log( data );
                    callback( data );
                }
            } );

            this.paymentMenu.open();
        },
    };

    delete window.wc_sb_seamless.init;
    $.extend( window.wc_sb_trustly, window.wc_sb_seamless );

    window.wc_sb_trustly.init( $( "form.checkout, form#order_review, form#add_payment_method" ) );
} );
