<?php
// Minimal custom course page with SCORM list and progress

// DEBUG MODE - Set to true to show debug output, false to hide
define('CUSTOMCOURSE_DEBUG', false);

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
    // Split the duration into parts
    $parts = explode(':', $duration);

    // Default all to zero
    $hours = isset($parts[0]) ? (int)$parts[0] : 0;
    $minutes = isset($parts[1]) ? (int)$parts[1] : 0;
    $seconds = isset($parts[2]) ? (float)$parts[2] : 0;

    return ($hours * 3600) + ($minutes * 60) + $seconds;
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

/**
 * Get all attempts for a user and SCORM, sorted by attempt number
 */
function get_all_attempts($scormid, $userid) {
    global $DB;
    return $DB->get_records('scorm_attempt', 
        ['scormid' => $scormid, 'userid' => $userid], 
        'attempt ASC'
    );
}

/**
 * Get the attempt with the highest score across all attempts
 */
function get_best_score_attempt($scormid, $userid, $element_ids, $scormVersion) {
    global $DB;
    
    $attempts = get_all_attempts($scormid, $userid);
    if (empty($attempts)) {
        return null;
    }
    
    $bestAttempt = null;
    $bestScore = -1;
    
    $scoreRawElement = $scormVersion != "SCORM_1.2" ? $element_ids['cmi.score.raw'] : $element_ids['cmi.core.score.raw '];
    
    foreach ($attempts as $attempt) {
        $scoreRaw = $DB->get_field('scorm_scoes_value', 'value', 
            ['attemptid' => $attempt->id, 'elementid' => $scoreRawElement]
        );
        
        if ($scoreRaw !== null && (float)$scoreRaw > $bestScore) {
            $bestScore = (float)$scoreRaw;
            $bestAttempt = $attempt;
        }
    }
    
    // If no score found, return the last attempt (most recent)
    return $bestAttempt ?: end($attempts);
}

/**
 * Calculate total time across all attempts
 */
function get_total_time_all_attempts($scormid, $userid, $element_ids, $scormVersion) {
    global $DB;
    
    $attempts = get_all_attempts($scormid, $userid);
    if (empty($attempts)) {
        return 0;
    }
    
    $totalTimeElement = $scormVersion != "SCORM_1.2" ? $element_ids['cmi.total_time'] : $element_ids['cmi.core.total_time'];
    $totalSeconds = 0;
    
    foreach ($attempts as $attempt) {
        $duration = $DB->get_field('scorm_scoes_value', 'value', 
            ['attemptid' => $attempt->id, 'elementid' => $totalTimeElement]
        );
        
        if ($duration !== null) {
            $seconds = $scormVersion != "SCORM_1.2" 
                ? scorm_duration_to_seconds($duration) 
                : scorm_duration_to_seconds_1_2($duration);
            $totalSeconds += $seconds;
        }
    }
    
    return $totalSeconds;
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

    <div class="custom-course-container">

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
            // $modinfo = get_fast_modinfo($courseid);

            // // Get all SCORM modules in course
            // $mods = get_fast_modinfo($course)->get_instances_of('scorm');

            // // fetch SCORM modules
            // $scormcms = $modinfo->get_instances_of('scorm');

            // $scorms = [];
            // foreach ($scormcms as $cm) {
            //     if ($cm->uservisible) {
            //         $scorms[] = $cm;
            //     }
            // }
            // Fetch course sections and build list of module IDs that should be shown
            $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
            $valid_cmids = [];
            foreach ($sections as $section) {
                if ($section->visible && $section->sequence) {
                    $sequence_array = array_filter(explode(',', $section->sequence));
                    $valid_cmids = array_merge($valid_cmids, $sequence_array);
                }
            }
            
            if (empty($valid_cmids)) {
                $mods = [];
            } else {
                // Fetch only SCORM modules that are in the valid sequence
                list($insql, $params) = $DB->get_in_or_equal($valid_cmids, SQL_PARAMS_NAMED);
                $params['courseid'] = $courseid;
                $sql = "
                    SELECT cm.id AS cmid, cm.instance AS scormid, cm.visible, s.*, cm.section
                    FROM {course_modules} cm
                    JOIN {modules} m ON m.id = cm.module
                    JOIN {scorm} s ON s.id = cm.instance
                    WHERE cm.id $insql
                    AND cm.course = :courseid
                    AND m.name = 'scorm'
                    AND cm.visible = 1
                    AND cm.deletioninprogress = 0
                ";
                $mods = $DB->get_records_sql($sql, $params);
            }
            
            // Sort SCORMs by their position in course_sections sequence
            $mods_array = array_values($mods);
            usort($mods_array, function($a, $b) use ($DB) {
                if ($a->section !== $b->section) {
                    return $a->section - $b->section;
                }
                $cs = $DB->get_record('course_sections', ['id' => $a->section]);
                if (!$cs || !$cs->sequence) {
                    return 0;
                }
                $sequence_array = array_filter(explode(',', $cs->sequence));
                $pos_a = array_search($a->cmid, $sequence_array);
                $pos_b = array_search($b->cmid, $sequence_array);
                if ($pos_a === false) $pos_a = PHP_INT_MAX;
                if ($pos_b === false) $pos_b = PHP_INT_MAX;
                return $pos_a - $pos_b;
            });
            $mods = $mods_array;

            $scorms = array_values($mods);
            $buttonlabel = '';


            // Check if user has an attempt for the first SCORM
            // if ($firstscorm) {
            //     // $attempt = $DB->get_field('scorm_scoes_track', 'attempt', ['userid' => $USER->id, 'scormid' => $firstscorm->instance]);
            //     $attempt = $DB->get_field('scorm_attempt', 'attempt', ['scormid'=>$firstscorm->instance,'userid'=>$USER->id]);
                

            //     if ($attempt) {
            //         $buttonlabel = 'Reprendre';
            //     }
            // }

            // First pass: determine which SCORM to link to in the button and calculate overall progress
            $buttonUrl = null;
            $buttonLabel = '';
            $scormIndexDone = -1;
            $scormIndexStarted = -1;
            $scormIndex = 0;
            $visibleModsCount = 0;
            $debugOutput = array();
            $debugFirstPass = array();

            foreach ($mods as $mod):
                if (!$mod) { continue; }
                
                $scorm = $DB->get_record('scorm', ['id' => $mod->scormid]);
                if (!$scorm) {
                    continue;
                }

                $scormIndex++;
                $visibleModsCount++;
                $cmid = $mod->cmid;
                $scormVersion = $scorm->version;
                $scormid = $scorm->id;
                $userid = $USER->id;

                // Get the best attempt (highest score) for this user and SCORM
                $bestAttempt = get_best_score_attempt($scormid, $userid, $element_ids, $scormVersion);
                $attemptid = $bestAttempt ? $bestAttempt->id : null;
                $attemptcount = $bestAttempt ? $bestAttempt->attempt : 0;
                
                // get progress
                $progress = $attemptid ? $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.progress_measure']]) : null;
                $progresspercent = ($progress !== null) ? $progress * 100 : 0;
                
                // get success and completion based on version
                $status_done = false;
                $completion_raw = null;
                $success_raw = null;
                
                if( $scormVersion != "SCORM_1.2") {
                    $success_raw = $attemptid ? $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.success_status']]) : null;
                    $completion_raw = $attemptid ? $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.completion_status']]) : null;
                    $status_done = ($completion_raw === 'completed' && $success_raw === 'passed');
                } else {
                    $lesson_status = $attemptid ? $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.core.lesson_status']]) : null;
                    $status_done = ($lesson_status === 'completed' || $lesson_status === 'passed');
                }

                // Track the last completed SCORM and first started SCORM
                // Only mark as done if progress >= 100 (don't rely on status_done if progress is 0)
                if($progresspercent >= 100) {
                    $scormIndexDone = $scormIndex;
                    if (CUSTOMCOURSE_DEBUG) {
                        $debugFirstPass[] = "SCORM $scormIndex marked as done (progress=$progresspercent%)";
                    }
                }
                if($progresspercent > 0 && $scormIndexStarted === -1) {
                    $scormIndexStarted = $scormIndex;
                }

                // If we haven't set a button URL yet, determine it based on sequence
                if ($buttonUrl === null) {
                    if ($scormIndexDone === -1 && $scormIndex === 1) {
                        // No SCORMs done yet - link to first SCORM
                        $buttonUrl = new moodle_url('/mod/scorm/view.php', ['id' => $cmid]);
                        $buttonLabel = get_string('btn-play', 'local_customcourse');
                    } elseif ($scormIndexDone >= 0 && $scormIndex === $scormIndexDone + 1) {
                        // This is the next unlocked SCORM after completion
                        $buttonUrl = new moodle_url('/mod/scorm/view.php', ['id' => $cmid]);
                        $buttonLabel = get_string('btn-play', 'local_customcourse');
                    }
                }
            endforeach;

            // Fallback: if no URL was set, link to first available SCORM
            if ($buttonUrl === null && $firstscorm) {
                $buttonUrl = new moodle_url('/mod/scorm/view.php', ['id' => $firstscorm->cmid]);
                $buttonLabel = get_string('btn-play', 'local_customcourse');
            }

            $courseprogresspercent =  round(($scormIndexDone > 0 ? $scormIndexDone : 0) * 100 / $visibleModsCount);

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
            if ($buttonUrl) {
                echo '<a href="' . $buttonUrl . '" class="btn btn-general">';
                echo $buttonLabel;
                echo '</a>';
            }
            echo '</div>';


            // ==========================================================================
            
            // $completioninfo = new \completion_info($course);
            ?>

            <div class="spacer-30"></div>
        </div>

        <?php if (!empty($mods)) : ?>
        <div class="scorm-grid">
            <?php 
            
            // Reset counter for second pass through the grid
            $scormIndex = 0;

            foreach ($mods as $mod):
                if (!$mod) { continue; }

                // Skip if SCORM record doesn’t exist (means deleted)
                $scorm = $DB->get_record('scorm', ['id' => $mod->scormid]);
                if (!$scorm) {
                    continue;
                }
                            
                $isScormAfterDone = false;
                $scormIndex++;
                // $cm = get_coursemodule_from_id('scorm', $mod->id, $course->id, false, MUST_EXIST);
                // $scorm = $DB->get_record('scorm', ['id' => $cm->instance], '*', MUST_EXIST);
                // $url = new moodle_url('/mod/scorm/view.php', ['id' => $cm->id]);
                        
                $cmid = $mod->cmid;   // from our query
                $url = new moodle_url('/mod/scorm/view.php', ['id' => $cmid]);
                // $scorm = $mod;

                // $intro = $mod->get_formatted_content();
                $intro = format_module_intro('scorm', $scorm, $mod->cmid);
                
                $scormid = $scorm->id;
                $userid = $USER->id;
                $scormVersion = $scorm->version;
                
                // Set defaults for all fields
                $style_completion = '';
                $style_success    = '';
                $style_score      = '';
                $success = get_string('unknown', 'local_customcourse');
                $completion = get_string('not_started', 'local_customcourse');
                $score_raw = '0';
                $scoreMax = '0';
                $score_html = '';
                $totaltime_in_seconds = '-';
                        
                // Get the best attempt (highest score) for this user and SCORM
                $bestAttempt = get_best_score_attempt($scormid, $userid, $element_ids, $scormVersion);
                $attemptid = $bestAttempt ? $bestAttempt->id : null;
                $attemptcount = $bestAttempt ? $bestAttempt->attempt : 0;
            
                // get progress
                $progress = $attemptid ? $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.progress_measure']]) : null;
                //echo $attemptid . ' // ' . $element_ids['cmi.progress_measure'] . ' // ' . $progress . '<br>';
                // normalize progress to percent
                $progresspercent = ($progress !== null) ? $progress * 100 : 0;

                // Get duration from all attempts - calculate total time across all attempts
                $totalTimeSeconds = get_total_time_all_attempts($scormid, $userid, $element_ids, $scormVersion);
                if ($totalTimeSeconds > 0) {
                    $totaltime_in_seconds = secondsToTime($totalTimeSeconds);
                }

                // score - only assign if data exists (from best attempt)
                $score_raw_db = $attemptid ? ($scormVersion != "SCORM_1.2" ? $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.score.raw']]) : $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.core.score.raw']])) : null;
                if (!is_null($score_raw_db)) {
                    $score_raw = round($score_raw_db);
                    $score_html = html_writer::span('', 'circle-progress', ['style' => "--percent:{$score_raw}"]);
                }

                // max score - only assign if data exists (from best attempt)
                $scoreMax_db = $attemptid ? ($scormVersion != "SCORM_1.2" ? $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.score.max']]) : $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.core.score.max']])) : null;
                if (!is_null($scoreMax_db)) {
                    $scoreMax = round($scoreMax_db);
                }
                
                // get success and completion - only assign if data exists
                $success_raw = null;
                $completion_raw = null;
                $lesson_status = get_string('not_started', 'local_customcourse');
                if( $scormVersion != "SCORM_1.2") {
                    $success_raw = $attemptid ? $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.success_status']]) : null;
                    $completion_raw = $attemptid ? $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.completion_status']]) : null;
                    $status_done = ($completion_raw === 'completed' && $success_raw === 'passed');
                } else {
                    $lesson_status_db = $attemptid ? $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>$element_ids['cmi.core.lesson_status']]) : null;
                    if (!is_null($lesson_status_db)) {
                        $lesson_status = $lesson_status_db;
                    }
                    $status_done = ($lesson_status_db === 'completed' || $lesson_status_db === 'passed');
                }
                
                // Only update success if we have data
                if ($success_raw === 'passed') {
                    $style_success = 'green';
                    $success = get_string('success', 'local_customcourse');
                } elseif ($success_raw === 'failed') {
                    $style_success = 'red';
                    $success = get_string('failed', 'local_customcourse');
                } else {
                    // Keep default: 'unknown'
                }
                
                // Check if progress is 0 (not started)
                if ($progresspercent <= 0) {
                    $style_completion = 'black';
                    $completion = get_string('not_started', 'local_customcourse');
                    // Reset to defaults
                    $success = get_string('unknown', 'local_customcourse');
                    $style_success = '';
                    $score_raw = '0';
                    $scoreMax = '0';
                    $totaltime_in_seconds = '-';
                } else if ($completion_raw === 'completed') {
                    $style_completion = 'green';
                    $completion = get_string('completed', 'local_customcourse');
                    $progresspercent = 100;
                } elseif ($completion_raw === 'incomplete') {
                    $style_completion = 'blue';
                    $completion = get_string('incomplete', 'local_customcourse');
                    // If progress is 100%, mark as completed regardless of completion status
                    if ($progresspercent >= 100) {
                        $style_completion = 'green';
                        $completion = get_string('completed', 'local_customcourse');
                        $progresspercent = 100;
                    }
                } else {
                    // If progress > 0 but no completion data, show as incomplete/in progress
                    $style_completion = 'blue';
                    $completion = get_string('incomplete', 'local_customcourse');
                }
                if($scormVersion == "SCORM_1.2" && $lesson_status && ($lesson_status === 'completed' || $lesson_status === 'passed')) {
                    $completion = get_string('completed', 'local_customcourse');
                    $style_completion = 'green';
                    $progresspercent = 100;
                }

                // echo $scormIndex . ' - ' . $completion_raw . ' / ' . $success_raw . '<br>';

                // Check if SCORM is done
                // if($status_done) {
                //     $scormIndexDone = $scormIndex;
                // }                // Determine if this SCORM is unlocked (started or finished)
                // Only the FIRST incomplete SCORM should be 'current' and unlocked
                // Or if it's already started/in progress
                // OR if it's already completed
                $isUnlocked = false;
                if ($progresspercent >= 100) {
                    // Completed SCORMs are always unlocked
                    $isUnlocked = true;
                } else if ($progresspercent > 0) {
                    // In-progress SCORMs are unlocked
                    $isUnlocked = true;
                } else if ($scormIndexDone === -1 && $scormIndex === 1) {
                    // First SCORM when nothing is done yet
                    $isUnlocked = true;
                } else if ($scormIndexDone >= 0 && $scormIndex === $scormIndexDone + 1) {
                    // Only the NEXT SCORM after the last completed one
                    $isUnlocked = true;
                }
                
                // Determine cardclass
                if ($progresspercent >= 100) {
                    // Completed - 100% progress
                    $cardclass = 'completed';
                } else if ($isUnlocked && $progresspercent > 0) {
                    // Currently in progress
                    $cardclass = 'current';
                } else if ($isUnlocked && $progresspercent <= 0) {
                    // Only unlock the NEXT not-started SCORM (after completed one)
                    // Check if this is the next SCORM after last completed
                    if ($scormIndexDone >= 0 && $scormIndex === $scormIndexDone + 1) {
                        $cardclass = 'current';
                    } else if ($scormIndexDone === -1 && $scormIndex === 1) {
                        // First SCORM when nothing is done
                        $cardclass = 'current';
                    } else {
                        // Shouldn't reach here, but default to locked
                        $cardclass = 'locked';
                    }
                } else {
                    // Locked - not yet unlocked
                    $cardclass = 'locked';
                }
                
                // DEBUG OUTPUT
                $debugOutput[] = "SCORM Index: $scormIndex | Progress: $progresspercent% | scormIndexDone: $scormIndexDone | isUnlocked: " . ($isUnlocked ? 'YES' : 'NO');
                $debugOutput[] = "  └─ SCORM $scormIndex: cardclass=$cardclass";

                // Get localized SCORM title if available
                $scorm_title = $mod->name;
                // Normalize title: remove accents, replace spaces and apostrophes with underscores
                $normalized_title = iconv('UTF-8', 'ASCII//TRANSLIT', $scorm_title);
                $localized_title_key = str_replace([' ', "'"], '_', $normalized_title);
                if (get_string_manager()->string_exists($localized_title_key, 'local_customcourse')) {
                    $scorm_title = get_string($localized_title_key, 'local_customcourse');
                }
                
                // Determine button label based on progression
                if ($progresspercent >= 100) {
                    $cardButtonLabel = get_string('btn-play-again', 'local_customcourse');
                } else if ($progresspercent > 0) {
                    $cardButtonLabel = get_string('btn-continue', 'local_customcourse');
                } else {
                    $cardButtonLabel = get_string('btn-play', 'local_customcourse');
                }

                // Ensure completion and lesson_status are never empty
                if (empty($completion) || !isset($completion)) {
                    $completion = get_string('not_started', 'local_customcourse');
                }
                if (empty($lesson_status) || !isset($lesson_status)) {
                    $lesson_status = get_string('not_started', 'local_customcourse');
                }

            ?>
            <div class="scorm-card <?php echo $cardclass; ?>" data-id="<?php echo $mod->cmid; ?>" data-scormid="<?php echo $scormid; ?>">
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
                            <div class="scorm-title"><?php echo format_string($scorm_title); ?></div>
                        <?php else: ?>
                            <a href="<?php echo $url; ?>" class="scorm-title"><?php echo format_string($scorm_title); ?></a>
                        <?php endif; ?>
                    </strong>
                    <div class="bottom-part">
                        <div class="details">
                            <div class="inner-details">
                                <div class="columns">
                                    <div class="first-col col">
                                        <?php if( $scormVersion != "SCORM_1.2") : ?>
                                            <div><?php echo get_string('lbl_completion', 'local_customcourse'); ?><b><span class="<?php echo $style_completion; ?>"><?php echo $completion; ?></span></b></div>
                                            <div><?php echo get_string('lbl_success', 'local_customcourse'); ?><b><span class="<?php echo $style_success; ?>"><?php echo $success; ?></span></b></div>
                                        <?php else: ?>
                                            <div><?php echo get_string('lbl_completion', 'local_customcourse'); ?><b><span class="<?php echo $style_completion; ?>"><?php echo $lesson_status; ?></span></b></div>
                                            <div><?php echo get_string('lbl_success', 'local_customcourse'); ?><b><span class="<?php echo $style_success; ?>"><?php echo $success; ?></span></b></div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="second-col col">
                                        <div class="force-second-column"><?php echo get_string('lbl_time', 'local_customcourse'); ?><b><?php echo $totaltime_in_seconds; ?></b></div>

                                    
                                        <div><?php echo get_string('lbl_score', 'local_customcourse'); ?><b><span class="<?php echo $style_score; ?>"><?php echo ($scoreMax > 0) ? round(($score_raw / $scoreMax) * 100) . '%' : '0%'; ?></span></b></div>
                                    
                                    <?php /*

                                    <div class="score" data-score="<?php echo ($cardclass === 'locked' ? '' : $score_raw); ?>"><?php echo get_string('lbl_score', 'local_customcourse'); ?><b><span><?php echo ($cardclass === 'locked' ? '' : $score_html); ?></span></b></div>
                                    */ ?>

                                        <div style="display:none"><?php echo get_string('lbl_attempt', 'local_customcourse'); ?><b><?php echo $attemptcount; ?></b></div>

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
                        </div>
                    </div>
                    <div class="btns">
                        <?php if($cardclass === 'locked'): ?>
                            <div class="btn btn-play disabled"><?php echo get_string('btn-play', 'local_customcourse'); ?></div>
                        <?php else: ?>
                            <a href="<?php echo $url; ?>" class="btn btn-<?php echo ($progresspercent >= 100 ? 'play-again' : ($progresspercent > 0 ? 'continue' : 'play')); ?>"><?php echo $cardButtonLabel; ?></a>
                        <?php endif; ?> 
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- OUTPUT DEBUG INFO AFTER SECOND PASS -->
        <?php if (CUSTOMCOURSE_DEBUG) { ?>
        <div style='background: #f0f0f0; padding: 10px; margin: 10px; border: 1px solid #ccc;'>
            <strong>DEBUG OUTPUT:</strong><br>
            <?php 
            if (!empty($debugFirstPass)) {
                echo "<strong>First Pass:</strong><br>";
                foreach ($debugFirstPass as $msg) {
                    echo "$msg <br>";
                }
            }
            echo "<strong>Second Pass Details:</strong><br>";
            foreach ($debugOutput as $msg) {
                echo "$msg <br>";
            }
            ?>
        </div>
        <?php } ?>

        <div class="course-content">
            <?php //echo $OUTPUT->main_content(); ?>
        </div>

        <div class="spacer-120"></div>

</div> <!-- custom-course-container -->

<?php
// echo $OUTPUT->footer();
?>
</body>
</html>
