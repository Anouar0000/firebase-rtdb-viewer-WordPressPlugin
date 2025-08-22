jQuery(document).ready(function($) {
    // --- STATE MANAGEMENT ---
    let allIssuesData = [];
    let currentPage = 1;
    const itemsPerPage = 20;


    // --- LOGIC & RENDER FUNCTIONS ---
    function processRow($row, issueId, postId, ajaxAction) {
        const $buttons = $row.find('.actions-cell .button'); // Target all buttons in the cell
        const $spinner = $row.find('.row-spinner');
        
        $buttons.prop('disabled', true);
        $spinner.addClass('is-active');

        const postData = {
            action: ajaxAction,
            nonce: firebase_sync_data.nonce,
            issue_id: issueId,
            post_id: postId
        };

        const promise = $.post(firebase_sync_data.ajax_url, postData).done(function(response) {
            if (response.success) {
                const item = allIssuesData.find(d => d.id === issueId);
                if (item) {
                    if (ajaxAction === 'firebase_create_single_post') { item.status = 'draft_managed'; item.post_id = response.data.post_id; }
                    if (ajaxAction === 'firebase_link_single_post') { item.status = 'synced_manual'; }
                    if (ajaxAction === 'firebase_unlink_single_post') { item.status = 'match_unlinked'; }
                    if (ajaxAction === 'firebase_publish_single_post') { item.status = 'synced_managed'; }
                    
                    if (ajaxAction === 'firebase_update_single_post') {
                        // Special UI feedback for refresh
                        const $actionsCell = $row.find('.actions-cell');
                        $actionsCell.html('<span style="color: green; font-weight: bold;">✓ Refreshed!</span>');
                        setTimeout(() => renderSingleRow($row, item), 2000); // Restore after 2 seconds
                    } else {
                        renderSingleRow($row, item); // Update UI immediately for other actions
                    }
                }
            } else {
                $row.find('.actions-cell').html('<span style="color: red;">Error!</span>');
                $buttons.prop('disabled', false);
            }
        }).fail(function() {
             $row.find('.actions-cell').html('<span style="color: red;">Server Error!</span>');
             $buttons.prop('disabled', false);
        }).always(function() {
            $spinner.removeClass('is-active');
        });
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

        const sortOrder = $('#sort-filter').val();
        filteredData.sort((a, b) => {
            if (sortOrder === 'newest') {
                // Compare dates descending
                return new Date(b.date) - new Date(a.date);
            } else {
                // Compare dates ascending
                return new Date(a.date) - new Date(b.date);
            }
        });
        
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
            // We only create the basic shell here.
            let rowHtml = template
                .replace(/{{issueId}}/g, item.id)
                .replace('{{headline}}', item.headline)
                .replace('{{status}}', '')
                .replace('{{date}}', item.date);
            
            const $row = $(rowHtml);
            $tableBody.append($row);
            
            // The single row renderer does all the detailed work
            renderSingleRow($row, item);
        });
    }

function renderSingleRow($row, item) {
    let statusText = '', actionHtml = '', checkboxHtml = '<th scope="row" class="check-column"><input type="checkbox" class="row-checkbox" data-issue-id="' + item.id + '" data-post-id="' + item.post_id + '"></th>';
    
    let postLinks = '';
    if (item.post_id) {
        const editUrl = `/wp-admin/post.php?post=${item.post_id}&action=edit`;
        const previewUrl = `/?p=${item.post_id}&preview=true`;
        // Added a wrapper with a class for potential styling
        postLinks = '<span class="row-actions"><a href="' + previewUrl + '" target="_blank">Preview</a> | <a href="' + editUrl + '" target="_blank">Edit</a></span>';
    }

    switch (item.status) {
        case 'missing':
            statusText = 'Missing';
            actionHtml = '<button class="button button-small action-create" data-issue-id="' + item.id + '">Create Post</button>';
            break;
        case 'draft_managed':
            statusText = 'Draft (Managed)';
            actionHtml = '<button class="button button-primary button-small action-publish" data-issue-id="' + item.id + '" data-post-id="' + item.post_id + '">Publish</button>' + postLinks;
            break;
        case 'synced_managed':
            statusText = 'Synced (Up-to-date)';
            actionHtml = '<div class="button-group"><button class="button button-small action-refresh" data-issue-id="' + item.id + '" data-post-id="' + item.post_id + '">Refresh</button> <button class="button button-small action-unlink" data-issue-id="' + item.id + '" data-post-id="' + item.post_id + '">Unlink</button></div>' + postLinks;
            checkboxHtml = '<th scope="row" class="check-column"></th>';
            break;
        case 'synced_manual':
            statusText = 'Synced (Protected)';
            // Improved structure for consistency
            actionHtml = '<div class="button-group"><span>Protected</span> <button class="button button-small action-unlink" data-issue-id="' + item.id + '" data-post-id="' + item.post_id + '">Unlink</button></div>' + postLinks;
            checkboxHtml = '<th scope="row" class="check-column"></th>';
            break;
        case 'match_unlinked':
            statusText = 'Match Found (Unlinked)';
            actionHtml = '<button class="button button-primary button-small action-link" data-issue-id="' + item.id + '" data-post-id="' + item.post_id + '">Link Post</button>' + postLinks;
            break;
    }

    $row.find('.status-cell .status-label').text(statusText);
    $row.find('.actions-cell').html(actionHtml + '<span class="spinner row-spinner"></span>');
    $row.find('.check-column').replaceWith(checkboxHtml);
    $row.attr('class', 'status-' + item.status);
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



        // --- EVENT LISTENERS ---

    $('#scan-firebase-issues').on('click', function() {
        const $button = $(this);
        const $spinner = $button.siblings('.spinner');
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $('#firebase-sync-table-body').html('<tr><td colspan="4">Scanning...</td></tr>');
        $('.wp-list-table, #pagination-controls').show();
        $.post(firebase_sync_data.ajax_url, { action: 'firebase_scan_issues', nonce: firebase_sync_data.nonce })
            .done(response => {
                if (response.success) {
                    console.log('Data received from server:', response);
                    allIssuesData = response.data;
                    currentPage = 1;
                    renderDisplay();
                    $('#sync-tool-filters, #sync-tool-actions, .tablenav.bottom').show();
                } else {
                    $('#firebase-sync-table-body').html('<tr><td colspan="4">Error: ' + response.data + '</td></tr>');
                }
            })
            .fail(() => $('#firebase-sync-table-body').html('<tr><td colspan="4">Server error.</td></tr>'))
            .always(() => { $spinner.removeClass('is-active'); $button.prop('disabled', false); });
    });

    $('#status-filter, #search-filter').on('change keyup', () => { currentPage = 1; renderDisplay(); });
    $('#sort-filter').on('change', function() {renderDisplay(); });
    $('#pagination-controls').on('click', 'a', function(e) { e.preventDefault(); const newPage = $(this).data('page'); if (newPage) { currentPage = parseInt(newPage); renderDisplay(); } });

    $('#firebase-sync-table-body').on('click', '.action-create, .action-link, .action-unlink, .action-publish, .action-refresh', function() {
        const $button = $(this);
        const $row = $button.closest('tr');
        const issueId = $button.data('issue-id');
        const postId = $button.data('post-id') || null;
        let ajaxAction = '';
        if ($button.hasClass('action-create')) ajaxAction = 'firebase_create_single_post';
        if ($button.hasClass('action-link')) ajaxAction = 'firebase_link_single_post';
        if ($button.hasClass('action-unlink')) ajaxAction = 'firebase_unlink_single_post';
        if ($button.hasClass('action-publish')) ajaxAction = 'firebase_publish_single_post';
        if ($button.hasClass('action-refresh')) ajaxAction = 'firebase_update_single_post';
        processRow($row, issueId, postId, ajaxAction);
    });
    
    $('#create-selected').on('click', () => {
        const $rowsToProcess = $('#firebase-sync-table-body input.row-checkbox:checked').closest('tr.status-missing');
        if (!$rowsToProcess.length) { alert('No "Missing" items are selected.'); return; }
        processRowsSequentially($rowsToProcess);
    });

    $('#link-selected').on('click', () => {
        const $rowsToProcess = $('#firebase-sync-table-body input.row-checkbox:checked').closest('tr.status-match_unlinked');
        if (!$rowsToProcess.length) { alert('No "Match Found" items are selected.'); return; }
        processRowsSequentially($rowsToProcess);
    });

    $('#publish-selected').on('click', () => {
        const $rowsToProcess = $('#firebase-sync-table-body input.row-checkbox:checked').closest('tr.status-draft_managed');
        if (!$rowsToProcess.length) { alert('No "Draft" items are selected.'); return; }
        processRowsSequentially($rowsToProcess);
    });

    $('.wp-list-table').on('click', '#cb-select-all-1', function() {
        const isChecked = $(this).is(':checked');
        $('#firebase-sync-table-body .row-checkbox').prop('checked', isChecked);
    });

    // --- QUICK SYNC PROGRESS BAR LOGIC ---

$('#quick-sync-button').on('click', function() {
    const $button = $(this);
    const $progressBarContainer = $('#quick-sync-progress-bar-container');
    const $progressBar = $('#quick-sync-progress-bar');
    const $progressLabel = $('#quick-sync-progress-label');

    $button.prop('disabled', true);
    $progressLabel.text('Checking for issues to sync...').show();
    $progressBar.css('width', '0%');
    $progressBarContainer.show();

    // 1. Pre-flight check to get the list of IDs
    $.post(firebase_sync_data.ajax_url, {
        action: 'firebase_quick_sync_preflight',
        nonce: firebase_sync_data.quick_sync_nonce // We need a new nonce for this
    }).done(function(response) {
        if (response.success && response.data.issue_ids.length > 0) {
            processIssueQueue(response.data.issue_ids);
        } else {
            $progressLabel.text(response.data || 'No new issues to sync.');
            $button.prop('disabled', false);
        }
    }).fail(function() {
        $progressLabel.text('Error: Could not connect to the server.');
        $button.prop('disabled', false);
    });

    // 2. Function to process the queue of IDs one by one
    function processIssueQueue(issueIds) {
        let processedCount = 0;
        const totalCount = issueIds.length;
        $progressLabel.text(`Processing ${processedCount}/${totalCount}...`);

        function processNext() {
            if (issueIds.length === 0) {
                // We're done!
                $progressLabel.text(`Sync complete! Processed ${totalCount} items.`);
                $progressBar.css('width', '100%');
                $button.prop('disabled', false);
                // Optional: Reload the table data
                if ($('#scan-firebase-issues').length) {
                    $('#scan-firebase-issues').trigger('click');
                }
                return;
            }

            const issueId = issueIds.shift(); // Get the next ID from the front of the array

            $.post(firebase_sync_data.ajax_url, {
                action: 'firebase_quick_sync_process_single',
                nonce: firebase_sync_data.quick_sync_nonce, // Same new nonce
                issue_id: issueId
            }).always(function() {
                processedCount++;
                const percentage = (processedCount / totalCount) * 100;
                $progressBar.css('width', percentage + '%');
                $progressLabel.text(`Processing ${processedCount}/${totalCount}...`);
                
                // Process the next item in the queue
                processNext();
            });
        }

        // Start the process
        processNext();
    }
});
});

