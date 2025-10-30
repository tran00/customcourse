<?php
// Minimal custom course page with SCORM list and progress

require_once(__DIR__ . '/../../config.php');

global $DB, $USER, $PAGE, $OUTPUT;

$courseid = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);


// Check if user can view course
$context = context_course::instance($course->id);

// Instead of require_capability(), check enrollment:
require_login($course); // ensures user is logged in and enrolled

// var_dump($USER->id);
// var_dump($course->id);
// var_dump(has_capability('moodle/course:view', $context));

// Require the capability to view the course
// if (!has_capability('moodle/course:view', $context)) {
//     throw new required_capability_exception($context, 'moodle/course:view', 'nopermissions', '');
// }

// Optional: explicitly check if user is enrolled
if (!is_enrolled($context, $USER->id)) {
    print_error('notenrolled', 'error', '', $course->fullname);
}


// ==========================================================================
// get MOODLE element ids

// Elements we want to retrieve
$elements = [
    'cmi.completion_status',
    'cmi.score.raw',
    'cmi.score.min',
    'cmi.score.max',
    'cmi.success_status',
    'cmi.total_time',
    'cmi.progress_measure',
    'cmi.core.lesson_status',
    'cmi.core.score.raw ',
    'cmi.core.score.min ',
    'cmi.core.score.max ',
    'cmi.core.total_time'
];

// Fetch records from the Moodle DB API
list($insql, $params) = $DB->get_in_or_equal($elements, SQL_PARAMS_NAMED);
$records = $DB->get_records_select('scorm_element', "element $insql", $params, '', 'id, element');

// Reindex the result by element name
$element_ids = [];
foreach ($records as $record) {
    $element_ids[$record->element] = $record->id;
}


// ==========================================================================

// Minimal layout: no Boost navigation, no tabs
$PAGE->set_context($context);
$PAGE->set_url('/local/customcourse/index.php', ['id' => $courseid]);
$PAGE->set_pagelayout('base'); // base = minimal layout
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));

// header with boost tabs
// echo $OUTPUT->header();

// header without boost tabs but with moodle home / courses / links
// echo $OUTPUT->standard_top_of_body_html();

function scorm_duration_to_seconds($duration) {
    if (preg_match('/PT((\d+)H)?((\d+)M)?((\d+(\.\d+)?)S)?/', $duration, $matches)) {
        $hours = !empty($matches[2]) ? (int)$matches[2] : 0;
        $minutes = !empty($matches[4]) ? (int)$matches[4] : 0;
        $seconds = !empty($matches[6]) ? (float)$matches[6] : 0;
        return $hours * 3600 + $minutes * 60 + $seconds;
    }
    return 0;
}

function scorm_duration_to_seconds_1_2($duration) {
    list($hours, $minutes, $seconds) = explode(':', $duration);
    return ($hours * 3600) + ($minutes * 60) + (float)$seconds;
}   

function secondsToTime($seconds) {
    $seconds = (int) round($seconds);
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = floor($seconds % 60);

    $parts = [];
    if ($hours > 0) $parts[] = "{$hours}h";
    if ($minutes > 0) $parts[] = "{$minutes}m";
    $parts[] = "{$secs}s"; // always show seconds

    return implode('', $parts);
    
}

echo $OUTPUT->doctype();

?>

<html <?php echo $OUTPUT->htmlattributes(); ?>>
<head>
    <title><?php echo $PAGE->title; ?></title>
    <?php echo $OUTPUT->standard_head_html(); ?>

    <link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/local/customcourse/assets/css/styles.css">


</head>
<!-- NOTHING from Boost header will appear -->
 

<?php
// Rewrite URLs first
$summary = file_rewrite_pluginfile_urls(
    $course->summary,
    'pluginfile.php',
    $context->id,
    'course',
    'summary',
    null
);

// Then format
// echo format_text($summary, $course->summaryformat, ['context' => $context]);


// Use regex to find first <img> src
$courseimageurl = null;
if (preg_match('/<img[^>]+src="([^">]+)"/i', $summary, $matches)) {
    $courseimageurl = $matches[1];
}

if ($courseimageurl) :
    // echo '<div class="course-hero" style="background-image: url(' . $courseimageurl . ');">';
    // echo '<h1>' . format_string($course->fullname) . '</h1>';
    // echo '</div>';
    ?>
    
    <body <?php echo $OUTPUT->body_attributes(); ?> style="background-image:url(<?php echo $courseimageurl; ?>); ?>">

    <?php else: ?>

    <body <?php echo $OUTPUT->body_attributes(); ?>>

    <?php endif; ?>


<div class="course-header">


<?php

    $fs = get_file_storage();

    // Get the overview files (course image)
    $files = $fs->get_area_files($context->id, 'course', 'overviewfiles', 0, 'sortorder', false);

    if ($files) {
        $file = reset($files); // take the first image
        $courseimg_url = moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            null, //$file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );
    }

    
    ?>



    <?php

    // ==========================================================================
    // Get course modinfo (returns a course_modinfo object)
    $modinfo = get_fast_modinfo($courseid);

    // Get all SCORM modules in course
    $mods = get_fast_modinfo($course)->get_instances_of('scorm');

    // fetch SCORM modules
    $scormcms = $modinfo->get_instances_of('scorm');

    $scorms = [];
    foreach ($scormcms as $cm) {
        if ($cm->uservisible) {
            $scorms[] = $cm;
        }
    }

    // Get the first SCORM
    $firstscorm = reset($scorms);
    $buttonlabel = '';


    // Check if user has an attempt for the first SCORM
    // if ($firstscorm) {
    //     // $attempt = $DB->get_field('scorm_scoes_track', 'attempt', ['userid' => $USER->id, 'scormid' => $firstscorm->instance]);
    //     $attempt = $DB->get_field('scorm_attempt', 'attempt', ['scormid'=>$firstscorm->instance,'userid'=>$USER->id]);
        

    //     if ($attempt) {
    //         $buttonlabel = 'Reprendre';
    //     }
    // }

    $lockNext = false; // flag: once we find the first incomplete/not attempted SCORM, lock the rest
    $scormIndexDone = -1;
    $scormIndex = 0;


    foreach ($mods as $mod):
        if (!$mod) { continue; }
        $scormIndex++;
        $isScormAfterDone = false;
        $cm = get_coursemodule_from_id('scorm', $mod->id, $course->id, false, MUST_EXIST);
        $scorm = $DB->get_record('scorm', ['id' => $cm->instance], '*', MUST_EXIST);
        $url = new moodle_url('/mod/scorm/view.php', ['id' => $cm->id]);

        $scormVersion = $scorm->version;
       

        $scormid = $scorm->id;
        $userid = $USER->id;
        // Get all attempts for this user and SCORM
        $attemptid = $DB->get_field('scorm_attempt', 'id', ['scormid'=>$scormid,'userid'=>$userid]);
        $attemptcount = $DB->get_field('scorm_attempt', 'attempt', ['scormid'=>$scormid,'userid'=>$userid]);
        // get success and completion
        if( $scormVersion != "SCORM_1.2") {
            $success_raw = $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.success_status']]);
            $completion_raw = $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.completion_status']]);
            $status_done = ($completion_raw === 'completed' && $success_raw === 'passed');
        } else {
            $lesson_status = $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.core.lesson_status']]);
            $status_done = ($lesson_status === 'completed' || $lesson_status === 'passed');
        }
        // echo $scormIndex . ' - ' . $completion_raw . ' / ' . $success_raw . '<br>';

        // Check if SCORM is done
        if($status_done) {
            $scormIndexDone = $scormIndex;
        }

        if($scormIndexDone !== -1 && $scormIndexDone != $scormIndex) {
            // previous mod was done
            if($completion_raw === 'incomplete' || $success_raw === 'failed' ) {
                $buttonlabel = get_string('btn-continue', 'local_customcourse');
            } else {
                $buttonlabel = get_string('btn-play', 'local_customcourse');
            }
            break; // Exit the foreach loop and use current $url
        }
    endforeach;

    // if no scorm done yet, set button to first scorm
    if ($scormIndexDone === -1 && $firstscorm) {
        $url = new moodle_url('/mod/scorm/view.php', ['id' => $firstscorm->id]);
        $buttonlabel = get_string('btn-play', 'local_customcourse');
    }

    $courseprogresspercent =  round(($scormIndexDone > 0 ? $scormIndexDone : 0) * 100 / count($mods));

    // ==========================================================================
    ?>
    <div class="course-thumb"><img src="<?php echo $courseimg_url ; ?>" alt=""></div>
    <div class="course-progress-bar">
        <div class="course-progress-fill" data-progress="<?php echo $courseprogresspercent; ?>">
            <div class="course-fill" style="width: <?php echo $courseprogresspercent; ?>%"></div>
            <div class="time-percent">
                <div class="progress-percent"><?php echo $courseprogresspercent; ?>%</div>
            </div>
        </div>
    </div>
    <h1><?php echo format_string($course->fullname); ?></h1>
    <p><?php //echo format_string($course->summary); ?></p>

    <?php
    // ==========================================================================

    // 4. Print the button above the list
    echo '<div class="general-scorm-btn">';
    // echo '<a href="' . (new moodle_url('/mod/scorm/view.php', ['id' => $firstscorm->id])) . '" class="btn btn-general">';
    echo '<a href="' . $url . '" class="btn btn-general">';
    echo $buttonlabel;
    echo '</a>';
    echo '</div>';


    // ==========================================================================
    
    // $completioninfo = new \completion_info($course);
    ?>

    <div class="spacer-30"></div>
    <div class="spacer-30"></div>
</div>

<?php if (!empty($mods)) : ?>
<div class="scorm-grid">
    <?php 
    
    $lockNext = false; // flag: once we find the first incomplete/not attempted SCORM, lock the rest
    // $scormIndexDone = -1;
    $scormIndex = 0;

    foreach ($mods as $mod):
        if (!$mod) { continue; }
        
        $isScormAfterDone = false;
        $scormIndex++;
        $cm = get_coursemodule_from_id('scorm', $mod->id, $course->id, false, MUST_EXIST);
                
        $intro = $mod->get_formatted_content();
        $url = new moodle_url('/mod/scorm/view.php', ['id' => $cm->id]);
        
        $scorm = $DB->get_record('scorm', ['id' => $cm->instance], '*', MUST_EXIST);
        $scormid = $scorm->id;
        $userid = $USER->id;
        $scormVersion = $scorm->version;
        
        
        // Get all attempts for this user and SCORM
        $attemptid = $DB->get_field('scorm_attempt', 'id', ['scormid'=>$scormid,'userid'=>$userid]);
        $attemptcount = $DB->get_field('scorm_attempt', 'attempt', ['scormid'=>$scormid,'userid'=>$userid]);
    
        // get progress
        $progress = $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.progress_measure']]);
        //echo $attemptid . ' // ' . $element_ids['cmi.progress_measure'] . ' // ' . $progress . '<br>';
        // normalize progress to percent
        $progresspercent = $progress * 100;

        // score
        $score_raw = $scormVersion != "SCORM_1.2" ? $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.score.raw']]) : $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.core.score.raw']]);
        $score_raw = round($score_raw);
        $score_html = is_null($score_raw) ? '' : html_writer::span('', 'circle-progress', ['style' => "--percent:{$score_raw}"]);

        // max score
        $scoreMax = $scormVersion != "SCORM_1.2" ? $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.score.max']]) : $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.core.score.max']]);
        $scoreMax = round($scoreMax);
        
        // get success and completion
        $success_raw = get_string('unknown', 'local_customcourse');
        $completion_raw = get_string('unknown', 'local_customcourse');
        $completion = '';
        $lesson_status = '';
        if( $scormVersion != "SCORM_1.2") {
            $success_raw = $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.success_status']]);
            $completion_raw = $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.completion_status']]);
            $status_done = ($completion_raw === 'completed' && $success_raw === 'passed');
        } else {
            $lesson_status = $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.core.lesson_status']]);
            $status_done = ($lesson_status === 'completed' || $lesson_status === 'passed');
        }
        if ($success_raw === 'passed') {
            $style_success = 'green';
            $success = get_string('success', 'local_customcourse');
        } elseif ($success_raw === 'failed') {
            $style_success = 'red';
            $success = get_string('failed', 'local_customcourse');
        }   else {
            $success = ''; //get_string('unknown', 'local_customcourse');
        }
        if ($completion_raw === 'completed') {
            $style_completion = 'green';
            $completion = get_string('completed', 'local_customcourse');
            $progresspercent = 100;
        } elseif ($completion_raw === 'incomplete') {
            $style_completion = 'red';
            $completion = get_string('incomplete', 'local_customcourse');
        }   else {
            $completion = ''; // get_string('unknown', 'local_customcourse');
        }
        if($scormVersion == "SCORM_1.2" && $lesson_status && ($lesson_status === 'completed' || $lesson_status === 'passed')) {
            $completion = get_string('completed', 'local_customcourse');
            $style_completion = 'green';
            $progresspercent = 100;
        }

        // echo $scormIndex . ' - ' . $completion_raw . ' / ' . $success_raw . '<br>';

        $duration = $scormVersion != "SCORM_1.2" ? $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.total_time']]) : $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.core.total_time']]);
        // Convert duration to seconds
        $totaltime_in_seconds = $scormVersion != "SCORM_1.2" ? scorm_duration_to_seconds($duration) : scorm_duration_to_seconds_1_2($duration);
        $totaltime_in_seconds = secondsToTime($totaltime_in_seconds);


        // Check if SCORM is done
        // if($status_done) {
        //     $scormIndexDone = $scormIndex;
        // }

        // echo "current scorm index: $scormIndex / $scormIndexDone / $status_done<br>";
        if($scormIndex === $scormIndexDone + 1 && $scormIndexDone > -1 && !$status_done) {
            $isScormAfterDone = true;
        } 
        if ($isScormAfterDone || $scormIndexDone == -1 && $scormIndex == 1) {
            $cardclass = 'current';
        } else if ($status_done) {
            $cardclass = 'completed';
        } else {
            $cardclass = 'locked';
        }

    ?>
    <div class="scorm-card <?php echo $cardclass; ?>">
        <div class="scorm-thumb">
             <?php if ($cardclass === 'locked'): ?>
                <div class="scorm-title locked-title"><?php echo $intro; ?></div>
                <div class="lock"><img src="assets/img/icon_lock.png" alt=""></div>
            <?php else: ?>
                <a href="<?php echo $url; ?>"><?php echo $intro; ?></a>
            <?php endif; ?>
        </div>
        <div class="scorm-details">
            <strong class="title">
                <?php if ($cardclass === 'locked'): ?>
                    <div class="scorm-title"><?php echo format_string($mod->name); ?></div>
                <?php else: ?>
                    <a href="<?php echo $url; ?>" class="scorm-title"><?php echo format_string($mod->name); ?></a>
                 <?php endif; ?>
            </strong>
            <div class="bottom-part">
                <div class="details">
                    <div class="inner-details">
                        <div class="columns">
                            <?php if( $scormVersion != "SCORM_1.2") : ?>
                                <div><?php echo get_string('lbl_completion', 'local_customcourse'); ?><b><span class="<?php echo $style_completion; ?>"><?php echo $completion; ?></span></b></div>
                                <div><?php echo get_string('lbl_success', 'local_customcourse'); ?><b><span class="<?php echo $style_success; ?>"><?php echo $success; ?></span></b></div>
                            <?php else: ?>
                                <div><?php echo get_string('lbl_completion', 'local_customcourse'); ?><b><span class="<?php echo $style_completion; ?>"><?php echo $lesson_status; ?></span></b></div>
                                <div>&nbsp;</div>
                            <?php endif; ?>
                            <div><?php echo get_string('lbl_time', 'local_customcourse'); ?><b><?php echo ($cardclass === 'locked' ? '' :  $totaltime_in_seconds); ?></b></div>

                           
                            <div><?php echo get_string('lbl_score', 'local_customcourse'); ?><b><span class="<?php echo $style_score; ?>"><?php echo ($cardclass === 'locked' ? '' : $score_raw . '/' . $scoreMax); ?></span></b></div>
                           
                            <?php /*

                            <div class="score" data-score="<?php echo ($cardclass === 'locked' ? '' : $score_raw); ?>"><?php echo get_string('lbl_score', 'local_customcourse'); ?><b><span><?php echo ($cardclass === 'locked' ? '' : $score_html); ?></span></b></div>
                            */ ?>

                            <div style="visibility:hidden"><?php echo get_string('lbl_attempt', 'local_customcourse'); ?><b><?php echo $attemptcount; ?></b></div>

                            <?php /*
                            <div class="score-wrapper">
                                <div class="label"><?php echo get_string('lbl_score', 'local_customcourse'); ?></div>
                                <?php if( $score_raw != 0): ?>
                                    <div class="score" data-score="<?php echo ($cardclass === 'locked' ? '' : $score_raw); ?>" style="--value: <?php echo $score_raw; ?>;"></div>
                                <?php else: ?>
                                    <div></div>
                                <?php endif; ?>
                            </div>
                            */ ?>
                        </div>
                    </div>
                    <div class="btns">
                        <div class="btn btn-play ghost"><?php echo get_string('btn-play', 'local_customcourse'); ?></div>
                    </div>
                </div>
                <div class="details-bottom">
                    <div class="inner-details">
                        <div class="progress-bar">
                            <div class="progress-fill" data-progress="<?echo $progress; ?>">
                                <div class="fill" style="width: <?php echo $progresspercent; ?>%"></div>
                            </div>
                            <div class="time-percent">
                                <div class="progress-percent"><?php echo $progresspercent; ?>%</div>
                            </div>
                        </div>
                    </div>
                    <div class="btns">
                        <?php if($status_done): ?>
                            <a href="<?php echo $url; ?>" class="btn btn-play-again"><?php echo get_string('btn-play-again', 'local_customcourse'); ?></a>
                        <?php elseif ($attemptcount > 0): ?>
                            <a href="<?php echo $url; ?>" class="btn btn-continue"><?php echo get_string('btn-continue', 'local_customcourse'); ?></a>
                        <?php elseif ($attemptcount == 0 && $cardclass !== 'locked'): ?>
                            <a href="<?php echo $url; ?>" class="btn btn-play"><?php echo get_string('btn-play', 'local_customcourse'); ?></a>
                        <?php else: ?>
                            <div class="btn btn-play disabled"><?php echo get_string('btn-play', 'local_customcourse'); ?></div>
                        <?php endif; ?> 
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="course-content">
    <?php //echo $OUTPUT->main_content(); ?>
</div>

<div class="spacer-120"></div>

<?php
// echo $OUTPUT->footer();
?>
</body>
</html>
