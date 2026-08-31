/**
 * Handles clicks on ".bw-mark-reviewed" buttons rendered inside the
 * "Outdated pages" dashboard widget and the backend module. Uses event
 * delegation on `document`, since the widget's content is injected via
 * AJAX after this script runs.
 */
(function () {
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.bw-mark-reviewed');
        if (!button) {
            return;
        }

        event.preventDefault();

        var url = button.getAttribute('data-url');
        if (!url) {
            return;
        }

        button.disabled = true;

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
        }).then(function (response) {
            if (!response.ok) {
                button.disabled = false;
                return;
            }

            var item = button.closest('.bw-outdated-item') || button.closest('.bw-outdated-row');
            if (item) {
                var li = item.closest('li') || item.closest('tr');
                (li || item).remove();
            }
        }).catch(function () {
            button.disabled = false;
        });
    });
})();
