<?php
// Standard Moodle config.
require_once(__DIR__ . '/../../config.php');

use core_completion\info;

// Get course id from URL
$courseid = required_param('id', PARAM_INT);

// Make sure user is logged in
require_login($courseid);

// Load course
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Course context
$context = context_course::instance($course->id);

// Check capability: only users who can view the course
// require_capability('moodle/course:view', $context);

// Page setup
$PAGE->set_url('/local/customcourse/index.php', ['id' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('course');

// Output starts
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($course->fullname));

// Fetch SCORM modules
$mods = get_fast_modinfo($course)->get_instances_of('scorm');
$completioninfo = new \completion_info($course);

?>

=======================================

<?php

if (!empty($mods)) {
    echo '<div class="scorm-grid">';
    foreach ($mods as $mod) {
        if (!$mod) {
            continue;
        }

        $cm = get_coursemodule_from_id('scorm', $mod->id, $course->id, false, MUST_EXIST);

        // Completion info
        $cmcompletion = $completioninfo->get_data($cm, true, $USER->id);

        switch ($cmcompletion->completionstate) {
            case COMPLETION_COMPLETE:
                $status = 'Completed';
                $class = 'completed';
                $percent = 100;
                break;
            case COMPLETION_INCOMPLETE:
                $status = 'Incomplete';
                $class = 'incomplete';
                $percent = 50;
                break;
            default:
                $status = 'Not attempted';
                $class = 'notattempted';
                $percent = 0;
        }

        // Get formatted intro / description
        $intro = $mod->get_formatted_content();

        // SCORM URL
        $url = new moodle_url('/mod/scorm/view.php', ['id' => $cm->id]);

        echo '<div class="scorm-card ' . $class . '">';
        echo '<div class="scorm-thumb"><a href="' . $url . '">' . $intro . '</a></div>';
        echo '<div class="scorm-details">';
        echo '<a href="' . $url . '" class="scorm-title">' . format_string($mod->name) . '</a>';
        echo '<span class="scorm-status-badge ' . $class . '">' . $status . '</span>';
        echo '<div class="progress-bar"><div class="progress-fill" style="width:' . $percent . '%"></div></div>';
        echo '</div></div>';
    }
    echo '</div>';
} else {
    echo '<p>No SCORM modules in this course.</p>';
}

?>

=======================================

<?php

// Main content (like topics, sections)
echo '<div class="course-content">';
echo $OUTPUT->main_content();
echo '</div>';

// Footer
echo $OUTPUT->footer();
