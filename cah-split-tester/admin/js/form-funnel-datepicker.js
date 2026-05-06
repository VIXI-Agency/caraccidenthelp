/*!
 * Form funnel datepicker bootstrap
 */
(function ($) {
    'use strict';

    if (typeof $.fn.datepicker !== 'function') {
        return;
    }

    $(function () {
        $('.cah-date-input').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
            showAnim: 'fadeIn'
        });
    });
})(jQuery);

