jQuery(document).ready(function($) {
    $('#wphaven-support-form').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $submitBtn = $('#wphaven-submit-btn');
        const $messages = $('#wphaven-support-messages');
        const $successMessage = $('#wphaven-success-message');
        
        // Clear previous messages
        $messages.empty();
        $successMessage.hide();
        
        // Disable submit button and show loading
        $submitBtn.prop('disabled', true).text('Creating ticket...');
        
        // Prepare form data
        const formData = {
            action: 'wphaven_support_ticket',
            nonce: wphavenSupport.nonce,
            name: $('#wphaven-name').val(),
            email: $('#wphaven-email').val(),
            subject: $('#wphaven-subject').val(),
            description: $('#wphaven-description').val()
        };
        
        // Submit via AJAX
        $.post(wphavenSupport.ajax_url, formData, function(response) {
            if (response.success) {
                // Hide form and show success message
                $form.hide();
                $successMessage.show();
            } else {
                // Show error message
                const errorMessage = response.data?.message || 'An error occurred. Please try again.';
                $messages.html('<div class="notice notice-error"><p>' + errorMessage + '</p></div>');
            }
        }).fail(function() {
            $messages.html('<div class="notice notice-error"><p>Network error. Please check your connection and try again.</p></div>');
        }).always(function() {
            // Re-enable submit button
            $submitBtn.prop('disabled', false).text('Create Support Ticket');
        });
    });
});