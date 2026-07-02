jQuery(document).ready(function($) {

    const $trigger = $('.firebase-load-trigger');
    const $spinner = $trigger.find('.firebase-spinner');
    const $grid = $('#firebase-issues-grid');

    // Check if the trigger element even exists on the page
    if (!$trigger.length) {
        return;
    }

    // A flag to prevent multiple AJAX calls from firing at the same time
    let isLoading = false;

    const loadMoreIssues = () => {
        // If we are already loading, or if the trigger has been disabled, do nothing
        if (isLoading) {
            return;
        }

        isLoading = true;
        $spinner.show();

        // Get data from the trigger div's attributes
        let currentPage = parseInt($trigger.attr('data-page'), 10) || 1;
        const lang = $trigger.attr('data-lang');
        const perPage = parseInt($trigger.attr('data-per-page'), 10) || 10;
        const initialLimit = parseInt($trigger.attr('data-initial-limit'), 10) || 50;

        $.post(firebase_loader_data.ajax_url, {
            action: 'load_more_firebase_issues',
            nonce: firebase_loader_data.nonce,
            page: currentPage,
            lang: lang,
            per_page: perPage,
            initial_limit: initialLimit
        }).done(function(response) {
            if (!response.success) {
                stopLoading();
                return;
            }

            const html = response.data.html || '';
            if (html.trim() !== '') {
                $grid.append($(html));
            }

            // Increment the page number for the next time we scroll.
            $trigger.attr('data-page', currentPage + 1);

            if (!response.data.has_more) {
                stopLoading();
            }
        }).fail(function() {
            // Handle server errors by disabling the trigger
            stopLoading();
        }).always(function() {
            isLoading = false;
            $spinner.hide();
        });
    };

    const stopLoading = () => {
        // To stop, we simply disconnect the observer
        if (observer) {
            observer.disconnect();
        }
        $trigger.hide(); // Hide the spinner container
    };

    // Intersection Observer API: The modern way to detect when an element is visible
    const observer = new IntersectionObserver((entries) => {
        // The callback fires whenever the visibility of the trigger element changes
        if (entries[0].isIntersecting) {
            // The trigger div is now visible on the screen, so load more posts
            loadMoreIssues();
        }
    }, {
        // Options for the observer
        rootMargin: '0px 0px 400px 0px', // Start loading when the user is 400px away from the bottom
    });

    // Start observing the trigger element
    observer.observe($trigger[0]);

});
