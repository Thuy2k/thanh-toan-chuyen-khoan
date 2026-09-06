(function ($) {
    'use strict';

    /**
     * Quản lý giao dịch thanh toán — bảng VietinBank MAP.
     *
     * Submit form → POST AJAX → render 12 cột (12 dòng <tr>).
     *
     * Trường "Chi tiết" link sang trang chi tiết VietinBank:
     *   https://map.vietinbank.vn/trx-mgmt/ma/transaction-management/payment-transactions/<id>
     */

    var cfg = window.TTCK_VTX || {};

    function notice(level, msg) {
        var $box = $('.ttck-vtx-notice');
        if (!$box.length) {
            $box = $('<div class="ttck-vtx-notice notice" hidden></div>').insertBefore('.ttck-vtx-wrap');
        }
        $box.removeClass('notice-success notice-error notice-warning')
            .addClass('notice notice-' + level + ' is-dismissible')
            .html('<p>' + msg + '</p>')
            .prop('hidden', false);
    }

    function escapeHtml(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatAmount(raw) {
        var n = parseInt(raw, 10);
        if (isNaN(n) || n <= 0) return escapeHtml(raw);
        return n.toLocaleString('vi-VN');
    }

    function statusBadge(s) {
        var code = String(s || '').trim();
        var label = code;
        var klass = 'ttck-vtx-badge';
        // VietinBank trả về "00" = thành công, mã khác = thất bại
        if (code === '00') {
            klass += ' ttck-vtx-badge-ok';
        } else if (code !== '') {
            klass += ' ttck-vtx-badge-err';
        }
        return '<span class="' + klass + '">' + escapeHtml(label) + '</span>';
    }

    function rowHtml(t) {
        var txnId  = String(t.id || '');
        var url    = 'https://map.vietinbank.vn/trx-mgmt/ma/transaction-management/payment-transactions/' + encodeURIComponent(txnId);
        return '<tr>' +
            '<td class="ttck-vtx-sticky-left">' + escapeHtml(t.tranTime || '') + '</td>' +
            '<td>' + formatAmount(t.amount) + '</td>' +
            '<td>' + escapeHtml(t.transactionNumber || '') + '</td>' +
            '<td>' + escapeHtml(t.reqCardName || '') + '</td>' +
            '<td>' + escapeHtml(t.reqCardNo || '') + '</td>' +
            '<td>' + escapeHtml(t.virtualAccount || '') + '</td>' +
            '<td>' + escapeHtml(t.branchInfoName || '') + '</td>' +
            '<td>' + escapeHtml(t.terminalInfoName || '') + '</td>' +
            '<td>' + escapeHtml(t.methodInfoName || '') + '</td>' +
            '<td title="' + escapeHtml(t.transactionDescription || '') + '">' + escapeHtml(t.transactionDescription || '') + '</td>' +
            '<td class="ttck-vtx-sticky-right">' + statusBadge(t.status) + '</td>' +
            '<td class="ttck-vtx-sticky-right"><a href="' + escapeHtml(url) + '" target="_blank" rel="noopener">' +
                (cfg.i18n && cfg.i18n.detail ? cfg.i18n.detail : 'Chi tiết') +
            '</a></td>' +
        '</tr>';
    }

    function renderRows(rows) {
        var $body = $('.ttck-vtx-body');
        if (!rows.length) {
            $body.html('<tr><td colspan="12" class="ttck-vtx-empty">' +
                (cfg.i18n && cfg.i18n.empty ? cfg.i18n.empty : 'Không có giao dịch nào trong khoảng này.') +
            '</td>');
            return;
        }
        var html = rows.map(rowHtml).join('');
        $body.html(html);
    }

    /**
     * Chuyển yyyy-MM-dd (HTML5 date input) sang dd/MM/yyyy (chuẩn API).
     */
    function isoToApi(s) {
        if (!s) return '';
        var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) return s;
        return m[3] + '/' + m[2] + '/' + m[1];
    }

    /**
     * Chuyển dd/MM/yyyy về yyyy-MM-dd (để set vào <input type="date">).
     */
    function apiToIso(s) {
        if (!s) return '';
        var m = String(s).match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
        if (!m) return s;
        return m[3] + '-' + m[2] + '-' + m[1];
    }

    /**
     * Sinh HTML pagination bar: « ‹ [1] … 4 5 [6] 7 8 … [20] › »
     * Hiển thị tối đa 7 số trang liên tiếp quanh current + first/last.
     *
     * @param number current  Trang hiện tại (0-indexed, server-side).
     * @param number total    Tổng số bản ghi.
     * @param number size     Số dòng / trang.
     * @return string         HTML.
     */
    function paginationHtml(current, total, size) {
        if (!total || total <= 0) return '';
        var pages = Math.max(1, Math.ceil(total / size));
        if (pages <= 1) return '';

        function btn(label, page, opts) {
            opts = opts || {};
            var cls = 'ttck-vtx-page-btn button';
            if (opts.active)   cls += ' button-primary';
            if (opts.disabled) cls += ' disabled';
            var attrs = ' data-page="' + page + '"';
            if (opts.disabled) attrs += ' aria-disabled="true" tabindex="-1"';
            return '<button type="button" class="' + cls + '"' + attrs + '>' + label + '</button>';
        }

        // Tính cửa sổ trang hiển thị: tối đa 5 số quanh current, luôn có first + last.
        var windowSize = 5;
        var half = Math.floor(windowSize / 2);
        var winStart = Math.max(1, current + 1 - half);
        var winEnd   = Math.min(pages - 1, winStart + windowSize - 1);
        winStart = Math.max(1, winEnd - windowSize + 1);

        var html = '<div class="ttck-vtx-pagination-inner">';
        html += btn('«', 0, { disabled: current === 0 });
        html += btn('‹', current - 1, { disabled: current === 0 });

        if (winStart > 1) {
            html += btn('1', 0);
            if (winStart > 2) html += '<span class="ttck-vtx-page-ellipsis">…</span>';
        }
        for (var p = winStart; p <= winEnd; p++) {
            html += btn(String(p), p - 1, { active: (p - 1) === current });
        }
        if (winEnd < pages - 1) {
            if (winEnd < pages - 2) html += '<span class="ttck-vtx-page-ellipsis">…</span>';
            html += btn(String(pages), pages - 1);
        }

        html += btn('›', current + 1, { disabled: current >= pages - 1 });
        html += btn('»', pages - 1, { disabled: current >= pages - 1 });
        html += '</div>';
        return html;
    }

    function renderPagination(current, total, size) {
        var $nav = $('.ttck-vtx-pagination');
        var html = paginationHtml(current, total, size);
        $nav.html(html).prop('hidden', html === '');
    }

    $(function () {
        var $form = $('.ttck-vtx-filter');
        if (!$form.length) return;

        var $start = $form.find('[name="start_date"]');
        var $end   = $form.find('[name="end_date"]');
        var $page  = $form.find('[name="page"]');
        var $size  = $form.find('[name="size"]');

        // Cross-constraint: start <= end
        $start.on('change', function () { $end.attr('min', $start.val() || ''); });
        $end.on('change',   function () { $start.attr('max', $end.val() || ''); });

        // Đổi size → reset về trang 0.
        $size.on('change', function () { $page.val(0); });

        // Click pagination button.
        $(document).on('click', '.ttck-vtx-pagination .ttck-vtx-page-btn', function () {
            if ($(this).hasClass('disabled') || $(this).hasClass('button-primary')) return;
            var p = parseInt($(this).attr('data-page'), 10);
            if (isNaN(p) || p < 0) return;
            $page.val(p);
            $form.trigger('submit');
        });

        $form.on('submit', function (e) {
            e.preventDefault();
            var $btn = $form.find('.ttck-vtx-search');
            var startVal = $start.val();
            var endVal   = $end.val();

            // Validate: end >= start
            if (startVal && endVal && startVal > endVal) {
                notice('error', '"Từ ngày" phải nhỏ hơn hoặc bằng "Đến ngày".');
                return;
            }

            var data = {
                action:      'ttck_vietinbank_list_transactions',
                nonce:       cfg.nonce || '',
                start_date:  isoToApi(startVal),
                end_date:    isoToApi(endVal),
                page:        parseInt($page.val(), 10) || 0,
                size:        parseInt($size.val(), 10) || 20,
            };

            $btn.prop('disabled', true).data('old', $btn.text()).text(cfg.i18n && cfg.i18n.searching || 'Đang tải…');

            $.post(cfg.ajaxUrl || (window.ajaxurl || ''), data)
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        var msg = (resp && resp.data && resp.data.message) || 'Lỗi không xác định.';
                        notice('error', msg);
                        renderRows([]);
                        renderPagination(0, 0, data.size);
                        return;
                    }
                    var d = resp.data || {};
                    var rows = Array.isArray(d.rows) ? d.rows : [];
                    var total = d.total || rows.length;
                    var size = d.size || data.size;
                    var current = d.page || 0;
                    var pages = Math.max(1, Math.ceil(total / size));
                    var pageLabel = (current + 1) + ' / ' + pages;
                    notice('success', '✓ ' + (cfg.i18n && cfg.i18n.found ? cfg.i18n.found : 'Tìm thấy') + ' ' + total + ' ' +
                        (cfg.i18n && cfg.i18n.transactions ? cfg.i18n.transactions : 'giao dịch') + ' (trang ' + pageLabel + ')');
                    $('.ttck-vtx-summary').html(
                        'Tổng: <strong>' + total + '</strong> giao dịch — Trang ' + pageLabel
                    ).prop('hidden', false);
                    renderPagination(current, total, size);
                    renderRows(rows);
                })
                .fail(function (xhr) {
                    notice('error', 'HTTP ' + xhr.status + ' — không gọi được AJAX.');
                    renderRows([]);
                    renderPagination(0, 0, data.size);
                })
                .always(function () {
                    $btn.prop('disabled', false).text($btn.data('old') || '');
                });
        });
    });

})(jQuery);