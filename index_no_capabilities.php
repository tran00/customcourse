<?php
// local/customcourse/index.php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir.'/completionlib.php');

$id = required_param('id', PARAM_INT); // Course ID
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
$context = context_course::instance($course->id);

// Require login and capability
require_login($course);
require_capability('moodle/course:view', $context);

$PAGE->set_url('/local/customcourse/index.php', ['id' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));

// Output header
echo $OUTPUT->header();

// Course info
echo html_writer::tag('h1', format_string($course->fullname));
echo html_writer::div(format_text($course->summary), 'course-summary');

// Fetch SCORM modules in this course
$mods = get_fast_modinfo($course)->get_instances_of('scorm');

if (!empty($mods)) {
    echo html_writer::start_div('scorm-grid');

    foreach ($mods as $mod) {
        if (!$mod) continue;

        $cm = get_coursemodule_from_id('scorm', $mod->id, $course->id, false, MUST_EXIST);

        // SCORM record
        $scorm = $DB->get_record('scorm', ['id' => $cm->instance], '*', MUST_EXIST);

        // Intro / description
        $intro = $mod->get_formatted_content();

        // User attempts
        $numattempts = $DB->count_records_sql("
            SELECT COUNT(DISTINCT ssd.attemptid)
              FROM {scorm_scoes} s
              JOIN {scorm_scoes_data} ssd ON s.id = ssd.scoid
             WHERE s.scorm = :scormid
               AND ssd.userid = :userid
        ", [
            'scormid' => $scorm->id,
            'userid' => $USER->id
        ]);

        // Last attempt ID
        $lastattemptid = $DB->get_field_sql("
            SELECT MAX(ssd.attemptid)
              FROM {scorm_scoes} s
              JOIN {scorm_scoes_data} ssd ON s.id = ssd.scoid
             WHERE s.scorm = :scormid
               AND ssd.userid = :userid
        ", [
            'scormid' => $scorm->id,
            'userid' => $USER->id
        ]);

        $status = 'Not attempted';
        $score = '-';
        if ($lastattemptid) {
            $values = $DB->get_records_sql("
                SELECT ssv.elementid, ssv.value
                  FROM {scorm_scoes} s
                  JOIN {scorm_scoes_value} ssv ON s.id = ssv.scoid
                 WHERE s.scorm = :scormid
                   AND ssv.userid = :userid
                   AND ssv.attemptid = :attemptid
            ", [
                'scormid' => $scorm->id,
                'userid' => $USER->id,
                'attemptid' => $lastattemptid
            ]);

            foreach ($values as $v) {
                if ($v->elementid === 'cmi.core.lesson_status') {
                    $status = $v->value;
                }
                if ($v->elementid === 'cmi.core.score.raw') {
                    $score = $v->value;
                }
            }
        }

        // SCORM view URL
        $url = new moodle_url('/mod/scorm/view.php', ['id' => $cm->id]);

        // Output card
        echo html_writer::start_div('scorm-card');

        echo html_writer::tag('h3', html_writer::link($url, format_string($mod->name)));
        echo html_writer::div($intro, 'scorm-intro');
        echo html_writer::div("Number of attempts: {$numattempts}", 'scorm-attempts');
        echo html_writer::div("Last attempt status: {$status}", 'scorm-status');
        echo html_writer::div("Last attempt score: {$score}", 'scorm-score');

        echo html_writer::end_div(); // scorm-card
    }

    echo html_writer::end_div(); // scorm-grid
} else {
    echo html_writer::div('No SCORM modules found in this course.');
}

// Output footer
echo $OUTPUT->footer();
