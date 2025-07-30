jQuery(document).ready(function($) {
    // --- STATE MANAGEMENT ---
    let allIssuesData = [];
    let currentPage = 1;
    const itemsPerPage = 20;

    // --- EVENT LISTENERS ---

    $('#scan-firebase-issues').on('click', function() {
        const $button = $(this);
        const $spinner = $button.siblings('.spinner');
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $('#firebase-sync-table-body').html('<tr><td colspan="4">Scanning... This may take a moment.</td></tr>');
        $('.wp-list-table, #pagination-controls').show();
        $.post(firebase_sync_data.ajax_url, {
            action: 'firebase_scan_issues',
            nonce: firebase_sync_data.nonce
        }).done(function(response) {
            if (response.success) {
                allIssuesData = response.data;
                currentPage = 1;
                renderDisplay();
                $('#sync-tool-filters, #sync-tool-actions, .tablenav.bottom').show();
            } else {
                $('#firebase-sync-table-body').html('<tr><td colspan="4">Error: ' + response.data + '</td></tr>');
            }
        }).fail(function() {
            $('#firebase-sync-table-body').html('<tr><td colspan="4">A server error occurred. Please try again.</td></tr>');
        }).always(function() {
            $spinner.removeClass('is-active');
            $button.prop('disabled', false);
        });
    });

    $('#status-filter, #search-filter').on('change keyup', function() { currentPage = 1; renderDisplay(); });
    $('#pagination-controls').on('click', 'a', function(e) { e.preventDefault(); const newPage = $(this).data('page'); if (newPage) { currentPage = parseInt(newPage); renderDisplay(); } });

    $('#firebase-sync-table-body').on('click', '.action-create, .action-link', function() {
        const $button = $(this);
        const issueId = $button.data('issue-id');
        const postId = $button.data('post-id') || null;
        const ajaxAction = $button.hasClass('action-create') ? 'firebase_create_single_post' : 'firebase_link_single_post';
        processRow($button.closest('tr'), issueId, ajaxAction, { post_id: postId });
    });
    
    $('#create-selected').on('click', function() {
        const $rowsToProcess = $('#firebase-sync-table-body input.row-checkbox:checked').closest('tr.status-missing');
        if (!$rowsToProcess.length) { alert('No "Missing" items are selected. This action only creates new posts.'); return; }
        processRowsSequentially($rowsToProcess);
    });

    $('#link-selected').on('click', function() {
        const $rowsToProcess = $('#firebase-sync-table-body input.row-checkbox:checked').closest('tr.status-match_unlinked');
        if (!$rowsToProcess.length) { alert('No "Match Found" items are selected. This action only links existing posts.'); return; }
        processRowsSequentially($rowsToProcess);
    });

    $('.wp-list-table').on('click', '#cb-select-all-1', function() {
        const isChecked = $(this).is(':checked');
        $('#firebase-sync-table-body .row-checkbox').prop('checked', isChecked);
    });


    // --- LOGIC & RENDER FUNCTIONS ---
    function processRow($row, issueId, ajaxAction, extraData = {}) {
        const $button = $row.find('.button');
        const $spinner = $row.find('.row-spinner');
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        const postData = $.extend({ action: ajaxAction, nonce: firebase_sync_data.nonce, issue_id: issueId }, extraData);
        const promise = $.post(firebase_sync_data.ajax_url, postData).done(function(response) {
            if (response.success) {
                const item = allIssuesData.find(d => d.id === issueId);
                if (ajaxAction === 'firebase_create_single_post') {
                    if (item) item.status = 'synced_managed';
                    $row.find('.status-cell').html('<span class="status-label status-synced-managed">Synced (Managed)</span>');
                } else {
                    if (item) item.status = 'synced_manual';
                    $row.find('.status-cell').html('<span class="status-label status-synced-manual">Synced (Protected)</span>');
                }
                $row.find('.actions-cell').html('Done!');
                $row.addClass('processed-success');
            } else {
                $row.find('.actions-cell').html('Error!');
                $button.prop('disabled', false);
            }
        }).fail(function() {
             $row.find('.actions-cell').html('Server Error!');
             $button.prop('disabled', false);
        }).always(function() { $spinner.removeClass('is-active'); });
        $row.data('ajaxPromise', promise);
    }
    
    function processRowsSequentially($rows) {
        if (!$rows.length) return;
        const $row = $rows.first();
        $row.find('.actions-cell .button').first().trigger('click');
        $.when($row.data('ajaxPromise')).always(function() {
            setTimeout(function() { processRowsSequentially($rows.slice(1)); }, 100);
        });
    }

    function renderDisplay() {
        const statusFilter = $('#status-filter').val();
        const searchFilter = $('#search-filter').val().toLowerCase();
        const filteredData = allIssuesData.filter(item => {
            let statusMatch = false;
            switch (statusFilter) {
                case 'all': statusMatch = true; break;
                case 'synced': statusMatch = ['synced_managed', 'synced_manual'].includes(item.status); break;
                case 'unsynced': statusMatch = ['missing', 'match_unlinked'].includes(item.status); break;
                default: statusMatch = item.status === statusFilter; break;
            }
            const searchMatch = item.headline.toLowerCase().includes(searchFilter);
            return statusMatch && searchMatch;
        });
        
        const totalItems = filteredData.length;
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        currentPage = Math.min(currentPage, totalPages) || 1;

        // ** THESE TWO LINES WERE MISSING AND ARE NOW FIXED **
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;
        
        const paginatedData = filteredData.slice(startIndex, endIndex);

        renderTableRows(paginatedData);
        renderPagination(totalItems, totalPages);
    }
    
function renderTableRows(data) {
    const $tableBody = $('#firebase-sync-table-body');
    const template = $('#sync-row-template').html();
    $tableBody.empty();

    if (data.length === 0) {
        $tableBody.html('<tr><td colspan="4">No issues match your criteria.</td></tr>');
        return;
    }

    data.forEach(function(item) {
        let statusText = '';
        let actionHtml = '';
        let checkboxHtml = '<th scope="row" class="check-column"><input type="checkbox" class="row-checkbox" data-issue-id="' + item.id + '"></th>';

        // Determine the text and button based on the status
        switch (item.status) {
            case 'missing':
                statusText = 'Missing';
                actionHtml = '<button class="button button-small action-create" data-issue-id="' + item.id + '">Create Post</button>';
                break;
            case 'synced_managed':
                statusText = 'Synced (Managed)';
                actionHtml = 'Up-to-date';
                checkboxHtml = '<th scope="row" class="check-column"></th>'; // No checkbox
                break;
            case 'synced_manual':
                statusText = 'Synced (Protected)';
                actionHtml = 'Protected';
                checkboxHtml = '<th scope="row" class="check-column"></th>'; // No checkbox
                break;
            case 'match_unlinked':
                statusText = 'Match Found (Unlinked)';
                actionHtml = '<button class="button button-primary button-small action-link" data-issue-id="' + item.id + '" data-post-id="' + item.post_id + '">Link Post</button>';
                break;
        }

        // ** THIS IS THE FIX **
        // We replace all placeholders BEFORE creating the jQuery object.
        let rowHtml = template
            .replace(/{{issueId}}/g, item.id)
            .replace('{{headline}}', item.headline)
            .replace('{{status}}', statusText); // This was the missing part
        
        const $row = $(rowHtml);

        // Now we can set the more complex parts that need HTML
        $row.find('.actions-cell').html(actionHtml + '<span class="spinner row-spinner"></span>');
        $row.find('.check-column').replaceWith(checkboxHtml);
        $row.addClass('status-' + item.status);
        
        $tableBody.append($row);
    });
}
    
    function renderPagination(totalItems, totalPages) {
        const $pagination = $('#pagination-controls');
        $pagination.empty();
        if (totalPages <= 1) return;
        let paginationHtml = `<span class="displaying-num">${totalItems} items</span><span class="pagination-links">`;
        const firstPageClass = currentPage === 1 ? 'disabled' : '';
        paginationHtml += `<a class="first-page ${firstPageClass}" data-page="1" href="#">«</a>`;
        const prevPageClass = currentPage === 1 ? 'disabled' : '';
        paginationHtml += `<a class="prev-page ${prevPageClass}" data-page="${currentPage - 1}" href="#">‹</a>`;
        paginationHtml += `<span class="paging-input"><input class="current-page" type="text" value="${currentPage}" size="2"> of <span class="total-pages">${totalPages}</span></span>`;
        const nextPageClass = currentPage === totalPages ? 'disabled' : '';
        paginationHtml += `<a class="next-page ${nextPageClass}" data-page="${currentPage + 1}" href="#">›</a>`;
        const lastPageClass = currentPage === totalPages ? 'disabled' : '';
        paginationHtml += `<a class="last-page ${lastPageClass}" data-page="${totalPages}" href="#">»</a>`;
        paginationHtml += `</span>`;
        $pagination.html(paginationHtml);
    }
});