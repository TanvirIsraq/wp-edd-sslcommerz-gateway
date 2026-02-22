jQuery(function ($) {
    if (typeof eddSSLCommerz === 'undefined') {
        return;
    }

    function isSSLCommerzSelected() {
        var selectedGateway = $('input[name="edd-gateway"]:checked').val() || $('input[name="payment-mode"]:checked').val();
        return selectedGateway === 'sslcommerz';
    }

    $('#edd-purchase-button, #sslczPayBtn').on('click', function (e) {
        if (!isSSLCommerzSelected()) {
            return;
        }

        e.preventDefault();

        var purchaseForm = $('#edd-purchase-form');
        var formData = purchaseForm.serialize();

        // Disable button to prevent double clicks
        var btn = $(this);
        var originalText = btn.val() || btn.text();
        btn.prop('disabled', true);
        if (btn.is('input')) {
            btn.val('Initializing...');
        } else {
            btn.text('Initializing...');
        }

        $.ajax({
            url: eddSSLCommerz.ajaxUrl,
            type: 'POST',
            data: {
                action: 'edd_sslcommerz_init_popup',
                nonce: eddSSLCommerz.nonce,
                form_data: formData
            },
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    if (response.data && response.data.url) {
                        // Use SSLCommerz EasyCheckout trigger if available
                        // We set the required attributes on a hidden or dummy button
                        // Or just redirect if we want to bypass the overlay issues
                        window.location.href = response.data.url;
                    } else {
                        alert('Payment initialized but redirect URL was missing.');
                        btn.prop('disabled', false);
                    }
                } else {
                    alert(response.data.message || 'Error initializing payment');
                    btn.prop('disabled', false);
                }
            },
            error: function () {
                alert('Connection error. Please try again.');
                btn.prop('disabled', false);
            },
            complete: function () {
                if (btn.is('input')) {
                    btn.val(originalText);
                } else {
                    btn.text(originalText);
                }
            }
        });
    });
});
