# Custom Course Progress Plugin

A Moodle local plugin that provides a custom course page with sequential SCORM progression tracking and visual feedback.

## Features

- **Sequential SCORM Progression**: SCORMs are unlocked one by one as users complete them successfully
- **Visual Status Indicators**: 
  - ✅ Green badges for completed SCORMs
  - 🔵 Blue badges for current/active SCORMs
  - 🔒 Lock overlay for unavailable SCORMs
- **Progress Tracking**: Course-wide progress bar showing overall completion percentage
- **Smart Button Logic**: Dynamic action buttons (Play, Continue, Replay, Locked) based on user progress
- **SCORM Version Support**: Compatible with both SCORM 1.2 and SCORM 2004 (cmi.3)
- **Responsive Design**: Clean, modern card-based layout for SCORM modules

## Installation

1. Clone or download this plugin into your Moodle `local/customcourse/` directory
2. Visit your Moodle admin panel to trigger the plugin installation
3. The plugin will automatically create necessary database tables

## Usage

### Accessing the Plugin

Navigate to the custom course page using:
```
/local/customcourse/index.php?id=<course_id>
```

### How Sequential Unlocking Works

1. **First SCORM**: Always available to the user
2. **Completion Requirement**: A SCORM must be both `completed` AND `passed` to unlock the next one
3. **Next SCORM**: Once the current SCORM is successfully completed, the next one becomes available
4. **Locked SCORMs**: All SCORMs after the next available one remain locked until reached

### User Experience Flow

- Users see a main action button (`.general-scorm-btn`) pointing to the next available SCORM
- The SCORM grid displays all SCORMs with their status:
  - Completed SCORMs show a checkmark and can be replayed
  - Current SCORM shows a play indicator and is clickable
  - Locked SCORMs show a lock icon and are non-interactive
- Progress bars update in real-time as users complete SCORMs
- Attempt counts and scores are displayed for in-progress or completed SCORMs

## Project Structure

```
customcourse/
├── index.php                    # Main plugin file
├── version.php                  # Plugin version info
├── README.md                    # This file
├── db/
│   └── access.php              # User capability definitions
├── lang/
│   └── en/
│       └── local_customcourse.php  # English language strings
└── assets/
    ├── css/
    │   ├── styles.css          # Compiled styles
    │   ├── styles.css.map      # CSS source map
    │   └── styles.scss         # SCSS source
    └── img/
        └── icon_lock.png       # Lock icon for locked SCORMs
```

## Database Structure

The plugin uses Moodle's built-in SCORM tables:
- `scorm`: Main SCORM module data
- `scorm_attempt`: User attempt records
- `scorm_scoes_value`: SCORM element tracking data
- `scorm_element`: Element ID mappings for different SCORM versions

## Tracked SCORM Elements

The plugin tracks the following SCORM 2004 elements:
- `cmi.completion_status`: Completion state (completed/incomplete)
- `cmi.success_status`: Success state (passed/failed)
- `cmi.score.raw`: Raw score achieved
- `cmi.score.max`: Maximum possible score
- `cmi.progress_measure`: Progress percentage
- `cmi.total_time`: Time spent in SCORM

For SCORM 1.2, it tracks:
- `cmi.core.lesson_status`: Lesson completion status
- `cmi.core.score.raw`: Score achieved
- `cmi.core.total_time`: Time spent

## Language Strings

The plugin includes language strings in `lang/en/local_customcourse.php`:
- `lbl_completion`: Completion label
- `lbl_success`: Success status label
- `lbl_time`: Time spent label
- `lbl_score`: Score label
- `lbl_attempt`: Attempt count label
- `btn-play`: Play button text
- `btn-continue`: Continue button text
- `btn-play-again`: Replay button text
- `locked_message`: Message shown on locked SCORMs

## Configuration

No additional configuration is required beyond standard Moodle SCORM setup. However, you can:

1. **Customize Colors**: Edit `assets/css/styles.css` or `assets/css/styles.scss`
2. **Modify Lock Icon**: Replace `assets/img/icon_lock.png` with your preferred icon
3. **Adjust Language**: Add translations to `lang/[language_code]/local_customcourse.php`

## CSS Classes

Main CSS classes for styling:

- `.scorm-card`: Individual SCORM card container
- `.scorm-card.completed`: Card state for completed SCORMs
- `.scorm-card.current`: Card state for active SCORM
- `.scorm-card.locked`: Card state for locked SCORMs
- `.scorm-grid`: Container for all SCORM cards
- `.progress-bar`: Progress bar styling
- `.btn`: Button styling
- `.lock`: Lock overlay styling

## Course background

Course background is set by uploading a pattern image in course settings **Description/Course summary**
Course banner is set by uploading an image in course settings **Description/Course image**.
Course banner prefered ratio : 1920 x 557 px

To display Scorm thumbnail, **Display description on course page** checkbox in scorm settings General/Description should be ticked

## Troubleshooting

### Button Points to Wrong SCORM
If the main action button points to an incorrect SCORM ID:
- Verify that deleted SCORMs are properly removed from the course
- Check that SCORM completion statuses are correctly set in the database
- Clear browser cache and reload the page

### SCORM Won't Unlock
Ensure that:
- The SCORM is marked as both `completed` AND `passed`
- The user actually completed the SCORM
- Browser cache is cleared (students should hard refresh)

### Progress Bar Not Updating
- Confirm SCORM elements are being tracked in the database
- Verify that the `scorm_element` table has proper ID mappings
- Check SCORM version compatibility (1.2 vs 2004)

## Changelog

### Version 1.0.0
- Initial release
- Sequential SCORM progression
- Visual status indicators
- Progress tracking
- SCORM 1.2 and 2004 support
