<?php
use gradereport_rubrics\chartdata;
include_once(__DIR__ . '/chartdata.php');

if (!isset($course) || !isset($cm)) {
    throw new moodle_exception('Missing course or cm');
}

// fetch the data
$criteriaData = chartdata::get_criteria_score_data($course, $cm);

// DEBUG: print out the scores array
//echo '<h5>Debug: Raw Criteria Scores</h5>';
//echo '<pre>' . print_r($criteriaData, true) . '</pre>';

// then your existing empty-check and chart code...
if (empty($criteriaData)) {
    echo html_writer::div('No rubric scores found to display chart.', 'alert alert-warning');
    return;
}
// … rest of chart output …


// prepare data for Chart.js (labels on x-axis and corresponding values).
$labels = json_encode(array_keys($criteriaData));
$values = json_encode(array_values($criteriaData));


// js to gnerate the bar chart using Chart.js.
echo html_writer::script("
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('criteriaChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: $labels,
                datasets: [{
                    label: 'Total score for all students',
                    data: $values,
                    backgroundColor: '#F39C12',
                    borderColor: 'rgba(235, 151, 54, 0.56)',
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        }); 
    });
");
