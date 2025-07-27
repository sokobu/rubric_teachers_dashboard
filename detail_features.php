<?php
// grade/report/rubrics/detail_features.php
// Detail view with the editable scores and ability to save changes. plus the secidion tree

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/filelib.php');

// required parameters
$courseid   = required_param('id', PARAM_INT);
$activityid = required_param('activityid', PARAM_INT);
$userid     = required_param('userid', PARAM_INT);
$download   = optional_param('download', 0, PARAM_INT);

global $DB, $USER;
$course = get_course($courseid);
$cm     = get_coursemodule_from_id('assign', $activityid, $courseid, false, MUST_EXIST);

require_login($course);
$context = context_module::instance($cm->id);
require_capability('mod/assign:grade', $context);
$PAGE->requires->css(new moodle_url('/grade/report/rubrics/assets/datatables/custom.css'));



// getting student submission  
$submission = $DB->get_record('assign_submission', [
    'userid'     => $userid,
    'assignment' => $cm->instance,
    'latest'     => 1
], '*', IGNORE_MISSING);
if (!$submission) {
    print_error('nosubmission', 'gradereport_rubrics');
}

// download submission 
if ($download) {
    $stored = get_file_storage()->get_file_by_id($download);
    if (!$stored) {
        throw new moodle_exception('invalidfile', 'error');
    }
    send_stored_file($stored, 0, 0, true);
}

// get student name 
$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
$fullname = fullname($user);

// setup
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/grade/report/rubrics/detail_features.php', [
    'id'         => $courseid,
    'activityid' => $activityid,
    'userid'     => $userid
]));
$PAGE->set_title('Submission Detail');
$PAGE->set_heading(format_string($course->fullname));

// Header 
echo $OUTPUT->header();


// download submission button 
$fs    = get_file_storage();
$files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $submission->id, 'sortorder', false);
foreach ($files as $f) {
    if ($f->is_directory()) continue;
    $dl = new moodle_url('/grade/report/rubrics/detail_features.php', ['id'=>$courseid,'activityid'=>$activityid,'userid'=>$userid,'download'=>$f->get_id()]);
    echo html_writer::link($dl, $f->get_filename(), ['class'=>'btn-submission']);

}

//show edits history buttton 
echo html_writer::tag('button', get_string('vieweditlog', 'gradereport_rubrics'), [
    'type' => 'button',
    'class' => 'btn-history',
    'data-bs-toggle' => 'modal',
    'data-bs-target' => '#editLogModal'
]);

//decision tree button 
echo html_writer::tag('button', get_string('destree', 'gradereport_rubrics'), [
    'type' => 'button',
    'class' => 'btn-tree',
    'data-bs-toggle' => 'modal',
    'data-bs-target' => '#destree'
]);

//THE BIG CONTAINER STARTS HERE 
echo html_writer::start_div('rubric-breakdown-container');

//get the total grade 
$assigngrade = $DB->get_record('assign_grades', [
    'assignment' => $cm->instance,
    'userid' => $userid
], '*', IGNORE_MISSING);

//header part for the detail breakdown 
if ($assigngrade) {
    echo html_writer::tag('h4', get_string('rubricbreakdown', 'gradereport_rubrics'), ['class' => 'rubric-heading']);

    echo html_writer::start_div('rubric-summary-box');

    echo html_writer::start_div('rubric-summary-row');

    echo html_writer::tag('div', 
        'Rubric Grade: ' . number_format($assigngrade->grade, 2),
        ['class' => 'rubric-grade']
    );

    echo html_writer::tag('div',
        get_string('submittedby', 'gradereport_rubrics') . ': ' . s($fullname),
        ['class' => 'submitted-by']
    );

    echo html_writer::end_div(); // rubric-summary-row

    // if there is general feedback, put it here 
    $feedback = $DB->get_record('assignfeedback_comments', [
        'grade' => $assigngrade->id
    ], '*', IGNORE_MISSING);

    if ($feedback && !empty($feedback->commenttext)) {
        echo html_writer::div(
            format_text($feedback->commenttext, $feedback->commentformat),
            'rubric-general-feedback'
        );
    }

    echo html_writer::end_div(); // rubric-summary-box

    // container for AJAX
    echo html_writer::div('', 'rubric-message', ['id' => 'rubric-message']);

    // editable grades START HERE, get current grades here 
    $fillings = $DB->get_records('gradingform_rubric_fillings', ['instanceid' => $assigngrade->id]);
    $levels = $DB->get_records('gradingform_rubric_levels');
    $criteria = $DB->get_records('gradingform_rubric_criteria');

    $level_lookup = [];
    foreach ($levels as $lvl) {
        $level_lookup[$lvl->id] = $lvl;
    }

    $criterion_lookup = [];
    foreach ($criteria as $crit) {
        $criterion_lookup[$crit->id] = $crit;
    }

    //write to new table woop woop 
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/grade/report/rubrics/save_edits.php', [
            'id' => $courseid,
            'activityid' => $activityid,
            'userid' => $userid
        ]),
        'class' => 'rubric-edit-form'
    ]);

    echo html_writer::empty_tag('input', [
    'type'  => 'hidden',
    'name'  => 'sesskey',
    'value' => sesskey()
        ]);

        //pretty container for the criteria 
        echo html_writer::start_div('rubric-criteria-container');
    foreach ($fillings as $fill) {
        $criterion = $criterion_lookup[$fill->criterionid] ?? null;
        $level = $level_lookup[$fill->levelid] ?? null;

        if ($criterion && $level) {
            echo html_writer::start_div('rubric-criterion-box');
            echo html_writer::tag('h5', format_string($criterion->description), ['class' => 'criterion-title']);

            echo html_writer::start_div('criterion-score-edit');
            echo html_writer::label('Score:', 'score_' . $fill->criterionid);
            $leveloptions = $DB->get_records('gradingform_rubric_levels', ['criterionid' => $fill->criterionid]);

            $options = '';
            foreach ($leveloptions as $opt) {
                $selected = ($opt->id == $fill->levelid) ? 'selected' : '';
                $scorelabel = number_format((float)$opt->score, 2, '.', '') . ' – ' . format_string($opt->definition);
                $options .= html_writer::tag('option', $scorelabel, [
                    'value' => $opt->score,
                    $selected => true
                ]);
            }

            echo html_writer::tag('select', $options, [
                'name' => 'scores[' . $fill->criterionid . ']',
                'class' => 'form-select',
                'id' => 'score_' . $fill->criterionid
            ]);

            echo html_writer::end_div();

            echo html_writer::start_div('criterion-comment-edit');
            echo html_writer::label('Comment:', 'comment_' . $fill->criterionid);
            echo html_writer::tag('textarea', $fill->remark ?? '', [
                'name' => 'comments[' . $fill->criterionid . ']',
                'class' => 'form-control',
                'id' => 'comment_' . $fill->criterionid
            ]);
            echo html_writer::end_div();

            echo html_writer::end_div();
        }
    }

    echo html_writer::end_div();

    //save those changess 
    echo html_writer::tag('button', 'Save Changes', [
        'type' => 'submit',
        'class' => 'save-changes'
    ]);
    echo html_writer::end_tag('form');

    // AJAX handler
echo <<<JS
<script>
document.querySelector('form.rubric-edit-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);

    const response = await fetch(form.action, {
        method: 'POST',
        body: formData
    });

    const result = await response.json(); // ✅ FIXED

    const msgDiv = document.getElementById('rubric-message');
    msgDiv.innerHTML = result.message;

    if (result.status === 'success') {
        msgDiv.className = 'alert alert-success';
        setTimeout(() => location.reload(), 1500);
    } else if (result.status === 'nochange') {
        msgDiv.className = 'alert alert-warning';
    } else {
        msgDiv.className = 'alert alert-danger';
        msgDiv.innerHTML = '❌ An unexpected error occurred.';
    }
});
</script>
JS;


} else {
    echo html_writer::div('No grade data found for this user.', 'alert alert-danger');
}

echo html_writer::end_div();

// decision tree modal HTML (open heredoc)
echo <<<HTML
<!-- Modal -->
<div class="modal fade" id="destree" tabindex="-1" aria-labelledby="decisionTree" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="destreeLabel">Decision Tree</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="decision-tree-container" style="min-height: 400px;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
HTML;

// Load JSON
$jsonpath = __DIR__ . '/data/iris_tree.json';
$tree_json = file_get_contents($jsonpath);
$tree_data = json_decode($tree_json, true);
?>

<script src="https://d3js.org/d3.v7.min.js"></script>
<script>
    const decisionTreeData = <?php echo json_encode($tree_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;

    function renderTree(data) {
        const container = d3.select("#decision-tree-container");
        container.html('');

        const width = 600;
        const height = 400;

        const treeLayout = d3.tree().size([height, width - 160]);
        const root = d3.hierarchy(data);
        treeLayout(root);

        const svg = container.append("svg")
        .attr("viewBox", [0, 0, width, height])
        .style("max-width", "100%")
        .style("height", "auto")
        .append("g")
        .attr("transform", "translate(80,0)");

        svg.selectAll("line")
            .data(root.links())
            .enter()
            .append("line")
            .attr("x1", d => d.source.y)
            .attr("y1", d => d.source.x)
            .attr("x2", d => d.target.y)
            .attr("y2", d => d.target.x)
            .attr("stroke", "#ccc");

        svg.selectAll("rect")
            .data(root.descendants())
            .enter()
            .append("rect")
            .attr("x", d => d.y - 60)
            .attr("y", d => d.x - 15)
            .attr("width", 120)
            .attr("height", 30)
            .attr("rx", 6)
            .attr("ry", 6)
            .attr("fill", "#FAD7A0")
            .attr("stroke", "#D35400")
            .attr("stroke-width", 1.5);

        svg.selectAll("text")
            .data(root.descendants())
            .enter()
            .append("text")
            .attr("x", d => d.y)
            .attr("y", d => d.x + 5)
            .attr("text-anchor", "middle")
            .style("font-family", "sans-serif")
            .style("font-size", "10px")
            .text(d => d.data.name);
            }

    const modal = document.getElementById('destree');
    modal.addEventListener('shown.bs.modal', () => {
        renderTree(decisionTreeData);
    });
</script>

<?php
// view history modal
echo <<<HTML
<!-- Modal -->
<div class="modal fade" id="editLogModal" tabindex="-1" aria-labelledby="editLogModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editLogModalLabel">Rubric Edit History</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
HTML;

// now we insert the eeduts table in normal PHP
$editlog = $DB->get_records('rubric_grade_edits', [
    'submissionid' => $assigngrade->id,
    'userid'       => $userid
], 'timemodified DESC');

if ($editlog) {
    echo html_writer::start_tag('table', ['class' => 'rubric-edit-log']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Criterion');
    echo html_writer::tag('th', 'Old Score');
    echo html_writer::tag('th', 'New Score');
    echo html_writer::tag('th', 'Comment');
    echo html_writer::tag('th', 'Edited By');
    echo html_writer::tag('th', 'Time');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');

    echo html_writer::start_tag('tbody');
    foreach ($editlog as $entry) {
        $criterion = $DB->get_record('gradingform_rubric_criteria', ['id' => $entry->criterionid], 'description', IGNORE_MISSING);
        $editor = $DB->get_record('user', ['id' => $entry->editorid], '*', IGNORE_MISSING);
        foreach (['firstnamephonetic', 'lastnamephonetic', 'middlename', 'alternatename'] as $field) {
            if (!isset($editor->$field)) {
                $editor->$field = '';
            }
        }

        $editorname = $editor ? fullname($editor) : 'Unknown';
        $criterionname = $criterion ? $criterion->description : 'Unknown';
        $time = userdate($entry->timemodified, '%d.%m.%Y %H:%M');

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', format_string($criterionname));
        echo html_writer::tag('td', format_float($entry->oldscore, 2));
        echo html_writer::tag('td', format_float($entry->newscore, 2));
        echo html_writer::tag('td', s($entry->comment ?? ''));
        echo html_writer::tag('td', $editorname);
        echo html_writer::tag('td', $time);
        echo html_writer::end_tag('tr');
    }
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
} else { //what if no edits were made
    echo html_writer::div('No edits have been made to this submission yet.', 'text-muted');
}

// close the second * modal
echo <<<HTML
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
HTML;
?>
<?php
echo $OUTPUT->footer();
