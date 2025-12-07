import { Controller } from "@hotwired/stimulus";
import Chart from "chart.js/auto";

export default class extends Controller {
    static values = {
        labels: Array,
        counts: Array
    }

    connect() {
        if (!this.hasLabelsValue || !this.hasCountsValue) return;
        const ctx = this.element.getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: this.labelsValue,
                datasets: [{
                    label: 'Unique Visitors',
                    data: this.countsValue,
                    borderColor: 'rgba(255, 99, 132, 1)',
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    fill: true,
                    tension: 0.2,
                    pointRadius: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    title: { display: false }
                },
                scales: {
                    x: { title: { display: true, text: 'Date' } },
                    y: { title: { display: true, text: 'Unique Visitors' }, beginAtZero: true, precision: 0 }
                }
            }
        });
    }
}

