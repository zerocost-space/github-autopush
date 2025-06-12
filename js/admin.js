jQuery(document).ready(function($) {
    // Auto refresh logs every 10 seconds
    function refreshLogs() {
        $.ajax({
            url: githubAutopush.ajaxUrl,
            type: 'POST',
            data: {
                action: 'get_github_autopush_logs',
                nonce: githubAutopush.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    $('.github-autopush-logs tbody').html(response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('Log refresh failed:', error);
                if (xhr.status === 400) {
                    console.error('Invalid request. Nonce might be expired.');
                }
            }
        });
    }

    // Start auto refresh
    setInterval(refreshLogs, 10000);

    $('#clear-logs-button').on('click', function() {
        if (!confirm(githubAutopush.clearLogsConfirm)) {
            return;
        }

        $.ajax({
            url: githubAutopush.ajaxUrl,
            type: 'POST',
            data: {
                action: 'clear_github_autopush_logs',
                nonce: githubAutopush.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(githubAutopush.clearLogsSuccess);
                    location.reload();
                } else {
                    alert(githubAutopush.clearLogsError);
                }
            },
            error: function() {
                alert(githubAutopush.clearLogsError);
            }
        });
    });
});