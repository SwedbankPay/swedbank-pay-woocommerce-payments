jQuery(document).ready(function ($) {
    $(document.body).on('update_checkout', function () {
        initTelInput();
    });

    initTelInput();

    function initTelInput() {
        const telInput = document.querySelector("#billing_phone");

        var iti = window.intlTelInput(telInput, {
                utilsScript: WC_Gateway_Swedbank_Pay_Intl_Tel.utils_script,
                preferredCountries: ['SE', 'NO', 'FI', 'DK'],
                nationalMode: false,
                formatOnDisplay: true,
                customContainer: 'form-row-wide'
            }
        );

        telInput.addEventListener('keyup', formatIntlTelInput);
        telInput.addEventListener('change', formatIntlTelInput);

        function formatIntlTelInput() {
            if (typeof intlTelInputUtils !== 'undefined') { // utils are lazy loaded, so must check
                var currentText = iti.getNumber(intlTelInputUtils.numberFormat.E164);
                if (typeof currentText === 'string') { // sometimes the currentText is an object :)
                    iti.setNumber(currentText); // will autoformat because of formatOnDisplay=true
                }
            }
        }
    }
});
