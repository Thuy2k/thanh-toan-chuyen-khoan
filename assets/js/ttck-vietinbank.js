(function ($) {
    'use strict';

    var cfg = window.TTCK_VTB || {};
    var i18n = cfg.i18n || {};

    function notice(level, msg) {
        var $wrap = $('.ttck-vtb-wrap').first();
        if (!$wrap.length) return;
        var $box = $wrap.find('.ttck-vtb-notice');
        if (!$box.length) {
            $box = $('<div class="ttck-vtb-notice notice" hidden></div>').prependTo($wrap);
        }
        $box.removeClass('notice-success notice-error notice-warning')
            .addClass('notice notice-' + level + ' is-dismissible')
            .html('<p>' + msg + '</p>')
            .prop('hidden', false);
        var top = $box.offset().top - 50;
        if (top > 0) {
            $('html, body').animate({scrollTop: top}, 200);
        }
        if (level === 'success') {
            setTimeout(function () { $box.fadeOut(); }, 4000);
        }
    }

    function pretty(obj) {
        try { return JSON.stringify(obj, null, 2); } catch (e) { return String(obj); }
    }

    function call(action, data, $btn) {
        var payload = $.extend({action: action, nonce: cfg.nonce}, data || {});
        if ($btn) {
            $btn.prop('disabled', true).data('old', $btn.text()).text(i18n.processing || 'Đang xử lý…');
        }
        return $.post(cfg.ajaxUrl, payload)
            .done(function (resp) {
                if (!resp || !resp.success) {
                    var msg = (resp && resp.data && resp.data.message) || 'Lỗi không xác định.';
                    notice('error', msg);
                }
            })
            .fail(function (xhr) {
                notice('error', 'HTTP ' + xhr.status + ' ' + (xhr.statusText || '') + ' — không gọi được AJAX.');
            })
            .always(function () {
                if ($btn) {
                    $btn.prop('disabled', false).text($btn.data('old') || '');
                }
            });
    }

    /*
     * Đọc giá trị test form theo tên field. Nếu input rỗng → trả default cứng
     * (giống const DEFAULT_* trong TTCK_VietinBank_API).
     */
    function readTestForm($card) {
        return {
            captcha_resp: $card.find('[data-vtb-field="captcha_resp"]').val() || '123456',
            ip_address:   $card.find('[data-vtb-field="ip_address"]').val()   || '118.70.124.48',
            language:     $card.find('[data-vtb-field="language"]').val()     || 'vi',
        };
    }

    function readSearchForm($card) {
        return {
            start_date:  $card.find('[data-vtb-field="start_date"]').val(),
            end_date:    $card.find('[data-vtb-field="end_date"]').val(),
            search_type: $card.find('[data-vtb-field="search_type"]').val() || '0',
            page:        $card.find('[data-vtb-field="page"]').val()        || 0,
            size:        $card.find('[data-vtb-field="size"]').val()        || 20,
            sort:        $card.find('[data-vtb-field="sort"]').val()        || 'txnDate,desc',
            language:    $card.find('[data-vtb-field="language"]').val()    || 'vi',
        };
    }

    /*
     * Đọc config lưu DB — server render ra data-* hidden, JS không cần gọi
     * AJAX chỉ để lấy config.
     */
    function readConfig() {
        return {
            client_id:     $('[data-vtb-config="client_id"]').data('vtb-value') || '',
            username:      $('[data-vtb-config="username"]').data('vtb-value') || '',
            password_hash: $('[data-vtb-config="password_hash"]').data('vtb-value') || '',
            signature:     $('[data-vtb-config="signature"]').data('vtb-value') || '',
            merchant_id:   $('[data-vtb-config="merchant_id"]').data('vtb-value') || '',
        };
    }

    function buildLoginCurl(cfgValues, frm) {
        var body = {
            username:     cfgValues.username,
            password:     cfgValues.password_hash,
            captcha_resp: frm.captcha_resp,
            device:       { os: { name: null, version: null }, browser: { name: null, version: null }, location: { long: 0, lat: 0 } },
            ip_address:   frm.ip_address,
            language:     frm.language,
        };
        return [
            "curl -X POST 'https://map.vietinbank.vn/vtb/public/map/api/ma/no-auth/login' \\",
            "  -H 'Content-Type: application/json' \\",
            "  -H 'Accept: application/json, text/plain, */*' \\",
            "  -H 'ClientId: " + cfgValues.client_id + "' \\",
            "  -H 'Signature: " + cfgValues.signature + "' \\",
            "  -H 'Origin: https://map.vietinbank.vn' \\",
            "  -H 'Referer: https://map.vietinbank.vn/merchant/ma/login' \\",
            "  -H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Safari/605.1.15' \\",
            "  -d '" + JSON.stringify(body).replace(/'/g, "'\\''") + "'"
        ].join('\n');
    }

    function buildSearchCurl(cfgValues, frm, tokenPlaceholder) {
        var body = {
            searchType: frm.search_type,
            startDate:  frm.start_date,
            endDate:    frm.end_date,
        };
        var q = '?page=' + (frm.page || 0) + '&size=' + (frm.size || 20) + '&sort=' + (frm.sort || 'txnDate,desc');
        return [
            "curl -X POST 'https://map.vietinbank.vn/vtb/public/map/api/rpt-txnmng/api/ma/payment-transaction/search" + q + "' \\",
            "  -H 'Content-Type: application/json' \\",
            "  -H 'Accept: application/json, text/plain, */*' \\",
            "  -H 'ClientId: " + cfgValues.client_id + "' \\",
            "  -H 'merchantId: " + cfgValues.merchant_id + "' \\",
            "  -H 'x-lang: " + (frm.language || 'vi') + "' \\",
            "  -H 'Authorization: Bearer " + tokenPlaceholder + "' \\",
            "  -H 'Origin: https://map.vietinbank.vn' \\",
            "  -H 'Referer: https://map.vietinbank.vn/trx-mgmt/ma/transaction-management/payment-transactions' \\",
            "  -d '" + JSON.stringify(body).replace(/'/g, "'\\''") + "'"
        ].join('\n');
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        // Fallback cho trình duyệt cũ hoặc non-https.
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            document.body.removeChild(ta);
            return Promise.resolve();
        } catch (e) {
            document.body.removeChild(ta);
            return Promise.reject(e);
        }
    }

    /*
     * Mở <details> wrap (bỏ thuộc tính hidden + thêm open). Dùng khi có
     * response / curl preview mới cần hiển thị.
     */
    function revealDetails($el) {
        $el.prop('hidden', false).attr('open', '');
    }

    /*
     * Render response test: token preview + HTTP status + body thô.
     */
    function renderResponse($out, resp) {
        if (!resp) return;
        var html = '';
        if (resp.token_preview) {
            html += '<p class="ttck-vtb-pre-meta"><span>Token:</span> <code>' + $('<div>').text(resp.token_preview).html() + '</code></p>';
        }
        if (resp.response) {
            var r = resp.response;
            var statusCls = r.ok ? 'ttck-vtb-pre-meta-ok' : 'ttck-vtb-pre-meta-err';
            var statusIcon = r.ok ? '✓' : '✗';
            html += '<p class="ttck-vtb-pre-meta"><span>HTTP:</span> <code class="' + statusCls + '">' + (r.status || 0) + ' ' + statusIcon + '</code></p>';
            html += '<pre class="ttck-vtb-pre">' + $('<div>').text(pretty(r.data || r.raw)).html() + '</pre>';
        }
        $out.html(html);
        revealDetails($out.closest('.ttck-vtb-response-wrap'));
    }

    $(function () {

        // -------- Toggle password input (thay cho inline onclick cũ) --------
        $(document).on('change', '[data-toggle-target]', function () {
            var id = $(this).data('toggle-target');
            $('#' + id).toggle(this.checked);
        });

        // -------- Test login --------
        $(document).on('click', '.ttck-vtb-test-login', function () {
            var $btn = $(this);
            var $card = $btn.closest('.ttck-vtb-card');
            var $out = $card.find('.ttck-vtb-response').empty();
            var frm = readTestForm($card);
            call('ttck_test_vietinbank_login', {body: frm}, $btn).done(function (resp) {
                if (resp && resp.success) {
                    notice('success', resp.data.message);
                    renderResponse($out, resp.data);
                }
            });
        });

        // -------- Test search --------
        $(document).on('click', '.ttck-vtb-test-search', function () {
            var $btn = $(this);
            var $card = $btn.closest('.ttck-vtb-card');
            var $out = $card.find('.ttck-vtb-response').empty();
            var frm = readSearchForm($card);
            call('ttck_test_vietinbank_search', {body: frm}, $btn).done(function (resp) {
                if (resp && resp.success) {
                    notice('success', resp.data.message);
                    renderResponse($out, resp.data);
                }
            });
        });

        // -------- Copy curl (login) --------
        $(document).on('click', '.ttck-vtb-copy-curl-login', function () {
            var $card = $(this).closest('.ttck-vtb-card');
            var curl = buildLoginCurl(readConfig(), readTestForm($card));
            $card.find('.ttck-vtb-curl-preview').val(curl);
            revealDetails($card.find('.ttck-vtb-curl-wrap'));
            copyText(curl).then(
                function () { notice('success', i18n.copied || 'Đã copy curl.'); },
                function () { notice('warning', i18n.copyFail || 'Copy tự động thất bại — chọn thủ công bên dưới.'); }
            );
        });

        // -------- Copy curl (search) --------
        $(document).on('click', '.ttck-vtb-copy-curl-search', function () {
            var $card = $(this).closest('.ttck-vtb-card');
            /*
             * Token nằm server-side (transient), JS không đọc được. Placeholder
             * <BEARER_TOKEN> — admin chạy Test login trước, copy accessToken từ
             * response rồi thay vào curl này.
             */
            var curl = buildSearchCurl(readConfig(), readSearchForm($card), '<BEARER_TOKEN>');
            $card.find('.ttck-vtb-curl-preview').val(curl);
            revealDetails($card.find('.ttck-vtb-curl-wrap'));
            copyText(curl).then(
                function () { notice('success', i18n.copied || 'Đã copy curl.'); },
                function () { notice('warning', i18n.copyFail || 'Copy tự động thất bại.'); }
            );
        });

    });
})(jQuery);