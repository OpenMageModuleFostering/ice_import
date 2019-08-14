//prevent conflict of Prototype and jQuery libraries
jQuery.noConflict();

//script which starts after page is loaded
jQuery(document).ready(function (jQuery) {
    var block = jQuery('#iceimport-dashboard');
    if (block.length > 0) {
        var target = jQuery("#topSearchGrid_table").parents('.entry-edit');
        if (target.length > 0) {
            target.append(block);
            block.show();
        } else {
            var findTd = jQuery('.entry-edit').eq(0).parents('table').find('td').eq(0);
            if (findTd.length > 0) {
                findTd.append(block);
                block.show();
            }
        }
    }


});