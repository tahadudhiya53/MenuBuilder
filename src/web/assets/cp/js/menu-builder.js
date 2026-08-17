(function() {
    if (typeof Craft === 'undefined') {
        return;
    }

    /**
     * Shared namespace + notification/AJAX helpers used by every other
     * menu-builder-cp module. Kept dependency-free (no jQuery requirement
     * beyond what Craft/Garnish already load) so it can init before the DOM
     * is fully parsed.
     */
    window.MenuBuilder = window.MenuBuilder || {};

    /** Thin alias so every module shares one entry point for API calls. */
    window.MenuBuilder.request = function(method, action, options) {
        return Craft.sendActionRequest(method, action, options);
    };

    window.MenuBuilder.success = function(message) {
        Craft.cp.displaySuccess(message);
    };

    /**
     * Extracts a user-safe message from a failed Craft.sendActionRequest —
     * the spec's "don't fail silently, don't expose raw errors" rule (§21).
     */
    window.MenuBuilder.errorMessage = function(error, fallback) {
        var data = error && error.response && error.response.data;
        return (data && data.message) || fallback || Craft.t('menu-builder', 'A server error occurred. Your changes were not saved. Please try again.');
    };

    window.MenuBuilder.displayError = function(error, fallback) {
        Craft.cp.displayError(window.MenuBuilder.errorMessage(error, fallback));
    };
})();
