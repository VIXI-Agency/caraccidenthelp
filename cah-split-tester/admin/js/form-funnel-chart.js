/*!
 * Horizontal bar chart: % completed per step (Form funnel admin).
 */
(function () {
    'use strict';

    if (typeof Chart === 'undefined' || typeof window.cahSplitFunnelDash === 'undefined') {
        return;
    }

    var d = window.cahSplitFunnelDash;
    if (!d.labels || !d.pct || !d.labels.length) {
        return;
    }

    var canvas = document.getElementById('cahFunnelChart');
    if (!canvas) {
        return;
    }

    var ctx = canvas.getContext('2d');

    Chart.defaults.font.family = '"Segoe UI", system-ui, -apple-system, sans-serif';
    Chart.defaults.color = '#8b96ad';

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: d.labels,
            datasets: [{
                label: '% Completed',
                data: d.pct,
                backgroundColor: 'rgba(14,169,255,0.55)',
                borderRadius: { topRight: 5, bottomRight: 5 },
                borderSkipped: false
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (item) {
                            return item.raw != null ? item.raw.toFixed(2) + '%' : '';
                        }
                    }
                }
            },
            scales: {
                x: {
                    min: 0,
                    max: 100,
                    grid: {
                        color: 'rgba(255,255,255,0.06)'
                    },
                    ticks: {
                        callback: function (value) {
                            return value + '%';
                        },
                        precision: 0
                    },
                    border: { display: false }
                },
                y: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: {
                        font: { size: 11 }
                    }
                }
            }
        }
    });
})();
