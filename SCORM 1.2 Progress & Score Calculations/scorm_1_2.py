

# Lines 721-752: SCORM 1.2 Score Processing
if data and scorm_version == "1.2":
    # SCORM 1.2 processing (existing logic)
    score_of_module = data['highest_score']
    status_of_module = data['status']
    progress_of_module = data['module_progress']
    
    print(f"Module {module_instance} - Found SCORM 1.2 data:")
    print(f"  Score: {score_of_module}")
    print(f"  Status: {status_of_module}")
    print(f"  Progress: {progress_of_module}")
    
    # Normalize score if it's numeric
    if score_of_module:
        try:
            score_to_use = float(score_of_module)
            if 0 < score_to_use <= 1:
                score_of_module = score_to_use * 100
            elif 1 < score_to_use <= 100:
                score_of_module = score_to_use 
            elif 100 < score_to_use <= 1000:
                score_of_module = score_to_use / 10
            elif 1000 < score_to_use <= 10000:
                score_of_module = score_to_use / 100
            elif score_to_use > 10000:
                magnitude = len(str(int(score_to_use))) - 2
                if magnitude > 0:
                    divisor = 10 ** magnitude
                    score_of_module = score_to_use / divisor
                else:
                    score_of_module = 100
        except (ValueError, TypeError):
            pass


# Lines 905-1016: SCORM 1.2 Progress Processing
try:
    if scorm_version == "1.2" and progress_of_module != "0" and progress_of_module is not None:
        # SCORM 1.2 progress processing (your existing logic)
        import base64
        # import json
        import zlib
        
        progress_processed = False
        lastPosition = 0
        
        # Check if module is completed based on status
        if status_of_module and status_of_module.lower() in ['passed', 'completed', 'complete', 'satisfied']:
            lastPosition = 100
            progress_processed = True
            print(f"Module {module_instance} - Setting progress to 100% based on completion status")
        elif not progress_processed:
            try:
                # First, check if it's a direct numeric value
                try:
                    progress_float = float(progress_of_module)
                    if progress_float <= 1:
                        lastPosition = progress_float * 100
                    else:
                        lastPosition = progress_float
                    progress_processed = True
                    print(f"Module {module_instance} - Processed numeric progress: {progress_float} -> {lastPosition}%")
                except ValueError:
                    # Handle custom formats and base64/JSON data
                    if "::" in progress_of_module or "_" in progress_of_module:
                        if "1/1" in progress_of_module:
                            lastPosition = 100
                            status_of_module = "completed"
                            if score_of_module == 0 or score_of_module is None:
                                score_of_module = 100
                            progress_processed = True
                        elif "c:1" in progress_of_module:
                            lastPosition = 100
                            status_of_module = "completed"
                            if score_of_module == 0 or score_of_module is None:
                                score_of_module = 100
                            progress_processed = True
                    
                    if not progress_processed:
                        if progress_of_module.startswith('{'):
                            # Handle JSON data
                            try:
                                json_data = json.loads(progress_of_module)
                                if "progress" in json_data:
                                    prog_val = float(json_data["progress"])
                                    lastPosition = prog_val * 100 if prog_val <= 1 else prog_val
                                    progress_processed = True
                                elif "completionStatus" in json_data and json_data["completionStatus"] == "completed":
                                    lastPosition = 100
                                    progress_processed = True
                                elif "playerLastPosition" in json_data:
                                    # Process player position data
                                    UserlastPosition = json_data["playerLastPosition"]["lastPosition"]
                                    total_time = 0
                                    if "chapters" in json_data and "chapterId" in json_data["playerLastPosition"]:
                                        chapter_id = json_data["playerLastPosition"]["chapterId"]
                                        if chapter_id and chapter_id in json_data['chapters']:
                                            total_time = json_data['chapters'][chapter_id]['end']
                                    if total_time == 0 and "totalDuration" in json_data:
                                        total_time = json_data["totalDuration"]
                                    if total_time > 0 and UserlastPosition > 0:
                                        lastPosition = (UserlastPosition / total_time) * 100
                                        progress_processed = True
                                    if json_data.get('didReachEnd', False):
                                        lastPosition = 100
                                        progress_processed = True
                            except Exception as json_error:
                                print(f"Error parsing JSON suspend_data: {json_error}")
                        else:
                            # Try base64 decoding
                            try:
                                suspend_data = progress_of_module
                                suspend_data = suspend_data.replace("-", "+").replace("_", "/").replace("::", "").replace(":", "")
                                while len(suspend_data) % 4 != 0:
                                    suspend_data += "="
                                
                                decoded_data = base64.b64decode(suspend_data)
                                try:
                                    decompressed_data = zlib.decompress(decoded_data)
                                    dd = json.loads(decompressed_data.decode("utf-8"))
                                    
                                    if "playerLastPosition" in dd:
                                        UserlastPosition = dd["playerLastPosition"]["lastPosition"]
                                        if "chapters" in dd and "chapterId" in dd["playerLastPosition"]:
                                            chapter_id = dd["playerLastPosition"]["chapterId"]
                                            if chapter_id and chapter_id in dd['chapters']:
                                                total_time_of_module = dd['chapters'][chapter_id]['end']
                                                lastPosition = (UserlastPosition / total_time_of_module) * 100
                                                progress_processed = True
                                        if dd.get('didReachEnd', False):
                                            lastPosition = 100
                                            progress_processed = True
                                except Exception as decompress_error:
                                    print(f"Error decompressing data: {decompress_error}")
                            except Exception as base64_error:
                                print(f"Error with base64 decoding: {base64_error}")
            except Exception as e:
                print(f"Error parsing progress: {e}")
                lastPosition = 0
        
        # For videos or modules with score 100 but no progress, ensure progress is 100%
        if (score_of_module == 100 or (isinstance(score_of_module, float) and score_of_module > 99)) and lastPosition == 0 and not progress_processed:
            lastPosition = 100
            progress_processed = True
        
        # Cap between 0 and 100
        lastPosition = max(0, min(100, lastPosition))
        module_progress = lastPosition
        
    # Process progress data (common for both versions)
    # ...code continues...
    
    # If module is passed or completed, ensure progress is 100%
    if status_of_module == "passed" or score_of_module == 100 or score_of_module == "100":
        module_progress = 100
    
    module_completion[module_id] = module_progress
    prog_progress += module_progress
    
    print(f"Module {module_instance} - FINAL - Score: {score_of_module}, Status: {status_of_module}, Progress: {module_progress}%")
    
except Exception as e:
    print(f"Module {module_instance} - Error in progress calculation: {e}")
    module_completion[module_id] = 0



# Lines 672-716: SQL query to retrieve SCORM attempt data
sql_query = f'''
    WITH AttemptScores AS (
        SELECT 
            t1.id AS attempt_id,
            t1.scormid,
            t2.elementid,
            t2.value AS score_or_status
        FROM 
            mdl_scorm_attempt AS t1
        JOIN 
            mdl_scorm_scoes_value AS t2
        ON 
            t1.id = t2.attemptid
        WHERE 
            t1.userid = {moodle_id}
            AND t1.scormid = {module_instance}
            AND t2.elementid IN ({element_id}, 2, {progress_element_id})
    ),
    AttemptAggregated AS (
        SELECT 
            attempt_id,
            scormid,
            MAX(CASE WHEN elementid = {element_id} THEN CAST(score_or_status AS DECIMAL) END) AS score,
            MAX(CASE WHEN elementid = 2 THEN score_or_status END) AS status,
            MAX(CASE WHEN elementid = {progress_element_id} THEN score_or_status END) AS progress
        FROM 
            AttemptScores
        GROUP BY 
            attempt_id, scormid
    ),
    BestAttempt AS (
        SELECT *,
            ROW_NUMBER() OVER (PARTITION BY scormid ORDER BY score DESC, attempt_id DESC) as rn
        FROM AttemptAggregated
        WHERE score IS NOT NULL
    )
    SELECT 
        attempt_id,
        scormid,
        score as highest_score,
        status,
        progress as module_progress
    FROM BestAttempt
    WHERE rn = 1;
'''
mysql_cursor.execute(sql_query)
data = mysql_cursor.fetchone()

