//prevent conflict of Prototype and jQuery libraries
jQuery.noConflict();

//script which starts after page is loaded
jQuery(document).ready(function ( jQuery ) {

    jQuery('div#content').on('click', 'a.section-toggler-iceimport', function(e){
        toggleIceimportFieldset(this);
        e.preventDefault();
    });

    jQuery('div#content').on('click.go-to-section', 'a.section-toggler-trigger-iceimport', function(e){
        var handler = jQuery(this);
        var section = handler.data('href');
        if (typeof section != 'undefined') {
            toggleIceimportFieldset(jQuery(section).next('.entry-edit').find('.section-toggler-iceimport'), 'open');
        }
    });


    var request_url_explanations = jQuery('#iceshop_iceimport_importprod_root_importprod_iceimport_explanation_hidden');
    if(request_url_explanations.length>0){
        var url_explanations = Base64.decode(request_url_explanations.find('option')[0].innerHTML);
        var iceimport_explanation_length = jQuery(('#iceimport_explanation')).length;
        if(iceimport_explanation_length>0){
            jQuery(('#iceimport_explanation')).attr('href',url_explanations)
        }
        jQuery('#iceshop_iceimport_importprod_root_importprod_iceimport_explanation_hidden').parents('tr').remove();
    }

    refreshIceimportSystemCheck();

    buildIceshopInfo();

    cronQueue();

    jQuery(document).on('change', '#iceshop_iceimport_importprod_root_importprod_import_images', function () {
        cronQueue();
    });
});

function cronQueue() {
    if (jQuery('#iceshop_iceimport_importprod_root_importprod_import_images').length > 0) {
        var selected = jQuery('#iceshop_iceimport_importprod_root_importprod_import_images').val();

        if (selected == '1') {
            jQuery('#row_iceshop_iceimport_importprod_root_importprod_images_queue_processing_only').show();
        }
        if (selected == '0') {
            jQuery('#row_iceshop_iceimport_importprod_root_importprod_images_queue_processing_only').hide();
        }
    }
}

function buildIceshopInfo()
{
    var iceshop_info_handler = jQuery('#iceshop_about_iceshop_iframe');
    if (iceshop_info_handler.length > 0) {
        var iceshop_info_el = jQuery('#iceshop_about_iceshop_iframe_iceshop_iframe_hidden', iceshop_info_handler);
        var iceshop_info_url = Base64.decode(iceshop_info_el.find('option')[0].innerHTML);
        var wrapper = iceshop_info_el.closest('form#config_edit_form div.entry-edit');
        wrapper.find('div.section-config').remove();
        var iceshop_info_iframe_el = jQuery('<iframe></iframe>')
            .attr('src', iceshop_info_url)
            .attr('width', '100%')
            .attr('height', '1000')
            .text('Your browser doesn\'t work with iframe elements');
        iceshop_info_iframe_el.appendTo(wrapper);
        jQuery('div.entry-edit').closest('div.main-col-inner').find('div.content-header').find('table td.form-buttons').hide();
        jQuery('div.content-header-floating').find('table td.form-buttons').html('');
    }
};

function refreshIceimportSystemCheck(url_string) {
    if (typeof url_string == 'undefined') {
        var checking_dashboard = jQuery('#iceimport_information_dashboard');
        if (checking_dashboard.length > 0) {
            //we're on dashboard page, lod the code
            var request_url_el = jQuery('#iceimport_information_dashboard_check_system_hidden', checking_dashboard);
            var request_url = Base64.decode(request_url_el.find('option')[0].innerHTML);
            request_url_el.closest('table').remove();
            jQuery.getJSON(
                request_url,
                function (response) {
                    if (typeof response.structure != 'undefined') {
                        jQuery('div.entry-edit').html(response.structure);
                    }
                    if (typeof response.refresh_btn != 'undefined') {
                        jQuery('div.entry-edit').closest('div.main-col-inner').find('div.content-header').find('table td.form-buttons').html(response.refresh_btn);
                        jQuery('div.content-header-floating').find('table td.form-buttons').html(response.refresh_btn);
                    }
                }
            );
        }
    } else {
        jQuery.getJSON(
            Base64.decode(url_string),
            function (response) {
                if (typeof response.structure != 'undefined') {
                    jQuery('div.entry-edit').html(response.structure);
                }
                if (typeof response.refresh_btn != 'undefined') {
                    jQuery('div.entry-edit').closest('div.main-col-inner').find('div.content-header').find('table td.form-buttons').html(response.refresh_btn);
                    jQuery('div.content-header-floating').find('table td.form-buttons').html(response.refresh_btn);
                }
            }
        );
    }
};

function toggleIceimportFieldset(element, action_type)
{
    if (typeof element != 'undefined') {
        if (typeof action_type != 'undefined') {
            switch(action_type) {
                case 'open':
                    var handler = jQuery(element);
                    console.log(element);
                    if (!handler.hasClass('open')) {
                        handler.toggleClass('open');
                        handler.closest('.entry-edit').find('div.fieldset').toggleClass('iceimport-hidden');
                    }
                    return true;
                    break;
                case 'close':
                    var handler = jQuery(element);
                    if (handler.hasClass('open')) {
                        handler.toggleClass('open');
                        handler.closest('.entry-edit').find('div.fieldset').toggleClass('iceimport-hidden');
                    }
                    return true;
                    break;
            }
        } else {
            var handler = jQuery(element);
            handler.toggleClass('open');
            handler.closest('.entry-edit').find('div.fieldset').toggleClass('iceimport-hidden');
            return true;
        }
    }
    return false;
};

var Base64 = {
// private property
    _keyStr: "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",

// public method for encoding
    encode: function (input) {
        var output = "";
        var chr1, chr2, chr3, enc1, enc2, enc3, enc4;
        var i = 0;

        input = Base64._utf8_encode(input);

        while (i < input.length) {

            chr1 = input.charCodeAt(i++);
            chr2 = input.charCodeAt(i++);
            chr3 = input.charCodeAt(i++);

            enc1 = chr1 >> 2;
            enc2 = ((chr1 & 3) << 4) | (chr2 >> 4);
            enc3 = ((chr2 & 15) << 2) | (chr3 >> 6);
            enc4 = chr3 & 63;

            if (isNaN(chr2)) {
                enc3 = enc4 = 64;
            } else if (isNaN(chr3)) {
                enc4 = 64;
            }

            output = output +
                Base64._keyStr.charAt(enc1) + Base64._keyStr.charAt(enc2) +
                Base64._keyStr.charAt(enc3) + Base64._keyStr.charAt(enc4);

        }

        return output;
    },

// public method for decoding
    decode: function (input) {
        var output = "";
        var chr1, chr2, chr3;
        var enc1, enc2, enc3, enc4;
        var i = 0;

        input = input.replace(/[^A-Za-z0-9\+\/\=]/g, "");

        while (i < input.length) {

            enc1 = Base64._keyStr.indexOf(input.charAt(i++));
            enc2 = Base64._keyStr.indexOf(input.charAt(i++));
            enc3 = Base64._keyStr.indexOf(input.charAt(i++));
            enc4 = Base64._keyStr.indexOf(input.charAt(i++));

            chr1 = (enc1 << 2) | (enc2 >> 4);
            chr2 = ((enc2 & 15) << 4) | (enc3 >> 2);
            chr3 = ((enc3 & 3) << 6) | enc4;

            output = output + String.fromCharCode(chr1);

            if (enc3 != 64) {
                output = output + String.fromCharCode(chr2);
            }
            if (enc4 != 64) {
                output = output + String.fromCharCode(chr3);
            }

        }

        output = Base64._utf8_decode(output);

        return output;

    },

// private method for UTF-8 encoding
    _utf8_encode: function (string) {
        string = string.replace(/\r\n/g, "\n");
        var utftext = "";

        for (var n = 0; n < string.length; n++) {

            var c = string.charCodeAt(n);

            if (c < 128) {
                utftext += String.fromCharCode(c);
            }
            else if ((c > 127) && (c < 2048)) {
                utftext += String.fromCharCode((c >> 6) | 192);
                utftext += String.fromCharCode((c & 63) | 128);
            }
            else {
                utftext += String.fromCharCode((c >> 12) | 224);
                utftext += String.fromCharCode(((c >> 6) & 63) | 128);
                utftext += String.fromCharCode((c & 63) | 128);
            }

        }

        return utftext;
    },

// private method for UTF-8 decoding
    _utf8_decode: function (utftext) {
        var string = "";
        var i = 0;
        var c = 0;
        var c2 = 0;
        var c3 = 0;

        while (i < utftext.length) {

            c = utftext.charCodeAt(i);

            if (c < 128) {
                string += String.fromCharCode(c);
                i++;
            }
            else if ((c > 191) && (c < 224)) {
                c2 = utftext.charCodeAt(i + 1);
                string += String.fromCharCode(((c & 31) << 6) | (c2 & 63));
                i += 2;
            }
            else {
                c2 = utftext.charCodeAt(i + 1);
                c3 = utftext.charCodeAt(i + 2);
                string += String.fromCharCode(((c & 15) << 12) | ((c2 & 63) << 6) | (c3 & 63));
                i += 3;
            }

        }
        return string;
    }
};