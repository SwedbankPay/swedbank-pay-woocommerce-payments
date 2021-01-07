/* global wc_checkout_params */
jQuery( function( $ ) {
    'use strict';

    $( document ).ajaxComplete( function ( event, xhr, settings ) {
        if ( ( settings.url === wc_checkout_params.checkout_url ) || ( settings.url.indexOf( 'wc-ajax=complete_order' ) > -1 ) ) {
            const data = xhr.responseText;

            // Parse
            try {
                const result = $.parseJSON( data );

                // Check is response from payment gateway
                if ( ! result.hasOwnProperty( 'is_swedbank_pay_invoice' ) ) {
                    return false;
                }

                // Save js_url value
                wc_sb_invoice.setJsUrl( result.js_url );
            } catch ( e ) {
                return false;
            }
        }
    } );

    /**
     * Object to handle Invoice payment forms.
     */
    window.wc_sb_invoice = {
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
                .on( 'click', '#place_order', this.onSubmit )

                // WooCommerce lets us return a false on checkout_place_order_{gateway} to keep the form from submitting
                .on( 'submit checkout_place_order_payex_psp_invoice' );
        },

        onSubmit: function( e ) {
            if ( wc_sb_invoice.form_submit ) {
                return true;
            }

            if ( ! wc_sb_invoice.validateForm() ) {
                return false;
            }

            console.log( 'onSubmit' );

            if ( wc_sb_invoice.form.is( '.processing' ) ) {
                return false;
            }

            console.log( 'onSubmit1' );
            console.log(this.js_url);

            wc_sb_invoice.waitForJsUrl();
        },

        /**
         * Validate checkout fields on the checkout form
         * @return {boolean}
         */
        validateForm: function () {
            var $required_inputs,
                validated = true;

            // check to see if we need to validate shipping address
            if ( $( '#ship-to-different-address-checkbox' ).is( ':checked' ) ) {
                $required_inputs = $( '.woocommerce-billing-fields .validate-required, .woocommerce-shipping-fields .validate-required' ).find('input, select').not( $( '#account_password, #account_username' ) );
            } else {
                $required_inputs = $( '.woocommerce-billing-fields .validate-required' ).find('input, select').not( $( '#account_password, #account_username' ) );
            }

            if ( $required_inputs.length ) {
                $required_inputs.each( function() {
                    var $this = $( this ),
                        $parent           = $this.closest( '.form-row' ),
                        validate_required = $parent.is( '.validate-required' ),
                        validate_email    = $parent.is( '.validate-email' );

                    if ( validate_required ) {
                        if ( 'checkbox' === $this.attr( 'type' ) && ! $this.is( ':checked' ) ) {
                            validated = false;
                        } else if ( $this.val() === '' ) {
                            validated = false;
                        }
                    }

                    if ( validate_email ) {
                        if ( $this.val() ) {
                            /* https://stackoverflow.com/questions/2855865/jquery-validate-e-mail-address-regex */
                            var pattern = new RegExp(/^((([a-z]|\d|[!#\$%&'\*\+\-\/=\?\^_`{\|}~]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])+(\.([a-z]|\d|[!#\$%&'\*\+\-\/=\?\^_`{\|}~]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])+)*)|((\x22)((((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(([\x01-\x08\x0b\x0c\x0e-\x1f\x7f]|\x21|[\x23-\x5b]|[\x5d-\x7e]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(\\([\x01-\x09\x0b\x0c\x0d-\x7f]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]))))*(((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(\x22)))@((([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])*([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])))\.)+(([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])*([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])))\.?$/i);
                            if ( ! pattern.test( $this.val()  ) ) {
                                validated = false;
                            }
                        }
                    }
                });
            }

            return validated;
        },

        /**
         * Set Js url.
         *
         * @param url
         */
        setJsUrl: function ( url ) {
            console.log( url );
            this.js_url = url;
        },

        /**
         * Wait for JS url availability
         */
        waitForJsUrl: function () {
            let interval = window.setInterval( function () {
                if ( wc_sb_invoice.js_url ) {
                    window.clearInterval( interval );

                    wc_sb_invoice.initFrame( wc_sb_invoice.js_url );
                }
            }, 1000 );
        },

        /**
         * Init frame.
         *
         * @param url
         * @param callback
         */
        initFrame: function ( url, callback ) {
            if ( typeof callback === 'undefined' ) {
                callback = function () {};
            }

            // Load JS
            wc_sb_invoice.loadJs( url, function () {
                $.featherlight( '<div id="swedbank-pay-invoice">&nbsp;</div>', {
                    variant: 'featherlight-swedbank-pay-invoice',
                    persist: true,
                    closeOnClick: false,
                    closeOnEsc: false,
                    afterOpen: function () {
                        wc_sb_invoice.initPaymentMenu( 'swedbank-pay-invoice' );
                    },
                    afterClose: function () {
                        wc_sb_invoice.form.removeClass( 'processing' ).unblock();
                    }
                } );

                callback()
            } );
        },

        /**
         * Load JS
         * @param js
         * @param callback
         */
        loadJs: function ( js, callback ) {
            // Creates a new script tag
            let script = document.createElement( 'script' );

            // Set script tag params
            script.setAttribute( 'src', js );
            script.setAttribute( 'type', 'text/javascript' );
            script.setAttribute( 'async', '' );
            script.addEventListener( 'load', function () {
                callback();
            }, false );

            // Gets document head element
            let oHead = document.getElementsByTagName( 'head' )[0];
            if ( oHead ) {
                // Add script tag to head
                oHead.appendChild( script );
            }

            return script;
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

            // Load Invoice frame
            this.paymentMenu = window.payex.hostedView.invoice( {
                container: id,
                culture: WC_Gateway_Swedbank_Pay_Invoice.culture,
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
    }

    window.wc_sb_invoice.init( $( "form.checkout, form#order_review, form#add_payment_method" ) );
} );
