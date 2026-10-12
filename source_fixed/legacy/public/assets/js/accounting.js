(function () {
    'use strict';

    var source = document.getElementById('accountingChartData');
    if (!source) return;

    var payload;
    try {
        payload = JSON.parse(source.textContent || '{}');
    } catch (error) {
        console.error('Data chart akuntansi tidak valid.', error);
        return;
    }

    function css(name, fallback) {
        var value = getComputedStyle(document.body).getPropertyValue(name).trim();
        return value || fallback;
    }

    var primary = css('--kp-theme-primary', '#991b1b');
    var secondary = css('--kp-theme-secondary', '#dc2626');
    var accent = css('--kp-theme-accent', '#ef4444');
    var soft = css('--kp-theme-soft', '#fff1f2');
    var grid = 'rgba(148,163,184,.18)';
    var text = '#475569';

    function rgba(hex, alpha) {
        var value = String(hex || '').replace('#', '').trim();
        if (value.length === 3) value = value.split('').map(function (c) { return c + c; }).join('');
        if (!/^[0-9a-f]{6}$/i.test(value)) return 'rgba(153,27,27,' + alpha + ')';
        var number = parseInt(value, 16);
        return 'rgba(' + ((number >> 16) & 255) + ',' + ((number >> 8) & 255) + ',' + (number & 255) + ',' + alpha + ')';
    }

    function money(value) {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency', currency: 'IDR', maximumFractionDigits: 0
        }).format(Number(value || 0));
    }

    function baseOptions(indexAxis) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: indexAxis || 'x',
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, color: text, font: { weight: '700' } } },
                tooltip: { callbacks: { label: function (ctx) { return (ctx.dataset.label ? ctx.dataset.label + ': ' : '') + money(ctx.raw); } } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: text, maxRotation: 0, autoSkip: true } },
                y: { beginAtZero: true, grid: { color: grid }, ticks: { color: text, callback: function (v) { return new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 }).format(v); } } }
            }
        };
    }

    function emptyPlugin(message) {
        return {
            id: 'accountingEmpty' + Math.random(),
            afterDraw: function (chart) {
                var hasData = chart.data.datasets.some(function (dataset) {
                    return (dataset.data || []).some(function (value) { return Number(value) !== 0; });
                });
                if (hasData) return;
                var ctx = chart.ctx;
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = '#94a3b8';
                ctx.font = '700 13px system-ui, sans-serif';
                ctx.fillText(message || 'Belum ada data pada periode ini', chart.width / 2, chart.height / 2);
                ctx.restore();
            }
        };
    }

    if (typeof Chart === 'undefined') {
        document.querySelectorAll('.accounting-chart-box').forEach(function (box) {
            box.innerHTML = '<div style="display:grid;place-items:center;height:100%;min-height:220px;color:#64748b;font-weight:800;text-align:center;padding:20px">Chart.js tidak dapat dimuat. Periksa koneksi internet server/browser.</div>';
        });
        return;
    }

    Chart.defaults.font.family = 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
    Chart.defaults.color = text;

    var trend = payload.trend || {};
    var trendCanvas = document.getElementById('accountingTrendChart');
    if (trendCanvas) {
        new Chart(trendCanvas, {
            type: 'line',
            data: {
                labels: trend.labels || [],
                datasets: [
                    { label: 'Penjualan Bersih', data: trend.sales || [], borderColor: primary, backgroundColor: rgba(primary, .12), fill: true, tension: .34, pointRadius: 2, borderWidth: 2.5 },
                    { label: 'Laba Kotor', data: trend.grossProfit || [], borderColor: secondary, backgroundColor: rgba(secondary, .08), tension: .34, pointRadius: 2, borderWidth: 2 },
                    { label: 'Laba Bersih', data: trend.netProfit || [], borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.08)', tension: .34, pointRadius: 2, borderWidth: 2 },
                    { label: 'Arus Kas Bersih', data: trend.cashflow || [], borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.06)', borderDash: [6, 4], tension: .34, pointRadius: 1, borderWidth: 2 }
                ]
            },
            options: baseOptions(),
            plugins: [emptyPlugin()]
        });
    }

    var branches = payload.branches || {};
    var branchCanvas = document.getElementById('accountingBranchChart');
    if (branchCanvas) {
        var branchOptions = baseOptions('y');
        branchOptions.scales.x = { beginAtZero: true, grid: { color: grid }, ticks: { color: text, callback: function (v) { return new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 }).format(v); } } };
        branchOptions.scales.y = { grid: { display: false }, ticks: { color: text } };
        new Chart(branchCanvas, {
            type: 'bar',
            data: {
                labels: branches.labels || [],
                datasets: [
                    { label: 'Penjualan', data: branches.sales || [], backgroundColor: rgba(primary, .82), borderRadius: 8 },
                    { label: 'Laba Kotor', data: branches.grossProfit || [], backgroundColor: rgba(accent, .72), borderRadius: 8 },
                    { label: 'Laba Bersih', data: branches.netProfit || [], backgroundColor: 'rgba(22,163,74,.72)', borderRadius: 8 }
                ]
            },
            options: branchOptions,
            plugins: [emptyPlugin()]
        });
    }

    var payment = payload.payments || {};
    var paymentCanvas = document.getElementById('accountingPaymentChart');
    if (paymentCanvas) {
        new Chart(paymentCanvas, {
            type: 'doughnut',
            data: {
                labels: payment.labels || [],
                datasets: [{
                    data: payment.values || [],
                    backgroundColor: [primary, accent, '#2563eb', '#16a34a', '#f59e0b', '#7c3aed', '#0891b2', '#64748b'],
                    borderColor: '#fff', borderWidth: 3, hoverOffset: 5
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '64%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, color: text, font: { weight: '700' } } },
                    tooltip: { callbacks: { label: function (ctx) { return ctx.label + ': ' + money(ctx.raw); } } }
                }
            },
            plugins: [emptyPlugin()]
        });
    }

    var expenses = payload.expenses || {};
    var expenseCanvas = document.getElementById('accountingExpenseChart');
    if (expenseCanvas) {
        var expenseOptions = baseOptions('y');
        expenseOptions.plugins.legend.display = false;
        expenseOptions.scales.x = { beginAtZero: true, grid: { color: grid }, ticks: { color: text, callback: function (v) { return new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 }).format(v); } } };
        expenseOptions.scales.y = { grid: { display: false }, ticks: { color: text } };
        new Chart(expenseCanvas, {
            type: 'bar',
            data: {
                labels: expenses.labels || [],
                datasets: [{ label: 'Beban Operasional', data: expenses.values || [], backgroundColor: rgba(secondary, .8), borderRadius: 8 }]
            },
            options: expenseOptions,
            plugins: [emptyPlugin()]
        });
    }

    if (payload.autoPrint) {
        window.setTimeout(function () { window.print(); }, 900);
    }
})();
