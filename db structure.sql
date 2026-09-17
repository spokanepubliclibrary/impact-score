10.14.51.133:3306/		https://impact.spokanelibrary.org/my/index.php?route=/server/sql

   Showing rows 0 - 127 (128 total, Query took 0.0055 seconds.) [TABLE_NAME: ADMINS... - USERS...]


SELECT 
    TABLE_NAME,
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_KEY,
    EXTRA
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'impact_score'
ORDER BY TABLE_NAME, ORDINAL_POSITION;


TABLE_NAME   	COLUMN_NAME	DATA_TYPE	IS_NULLABLE	COLUMN_DEFAULT	COLUMN_KEY	EXTRA	
admins	id	int	NO	NULL	PRI	auto_increment	
admins	username	varchar	NO	NULL	UNI		
admins	password	varchar	NO	NULL			
calendar_upload	date_relative	varchar	YES	NULL			
calendar_upload	date	date	YES	NULL			
calendar_upload	title	varchar	YES	NULL			
calendar_upload	creator	varchar	YES	NULL			
calendar_upload	total_attendance	int	YES	NULL			
calendar_upload	attendance_notes	text	YES	NULL			
calendar_upload	private_event	tinyint	YES	NULL			
calendar_upload	changed	varchar	YES	NULL			
calendar_upload	status	varchar	YES	NULL			
form_prefill_values	id	int	NO	NULL	PRI	auto_increment	
form_prefill_values	form_id	int	NO	NULL	MUL		
form_prefill_values	question_id	int	NO	NULL	MUL		
form_prefill_values	prefill_value	text	YES	NULL			
form_profiles	id	int	NO	NULL	PRI	auto_increment	
form_profiles	team_id	int	NO	NULL	MUL		
form_profiles	name	varchar	NO	NULL			
form_profiles	bulk_entry	tinyint	NO	0			
form_profiles	program_type	enum	YES	program			
form_questions	id	int	NO	NULL	PRI	auto_increment	
form_questions	form_id	int	NO	NULL	MUL		
form_questions	question_id	int	NO	NULL	MUL		
locations	id	int	NO	NULL	PRI	auto_increment	
locations	name	varchar	NO	NULL			
programming_reports	id	int	NO	NULL	PRI	auto_increment	
programming_reports	start_date	date	NO	NULL	MUL		
programming_reports	end_date	date	NO	NULL			
programming_reports	config_json	longtext	NO	NULL			
programming_reports	created_at	timestamp	YES	CURRENT_TIMESTAMP		DEFAULT_GENERATED	
programming_reports	updated_at	timestamp	YES	CURRENT_TIMESTAMP		DEFAULT_GENERATED on update CURRENT_TIMESTAMP	
programs	id	bigint	NO	NULL	PRI	auto_increment	
programs	name	varchar	YES	NULL			
programs	team	varchar	YES	NULL			
programs	program_name	varchar	YES	NULL			
programs	total_score	int	YES	NULL			
programs	date	date	YES	NULL			
programs	attendance	int	YES	NULL			
programs	scaled_attendance	decimal	YES	NULL			
programs	adjusted_impact_score	decimal	YES	NULL			
programs	created_at	timestamp	YES	CURRENT_TIMESTAMP		DEFAULT_GENERATED	
programs	updated_at	timestamp	YES	CURRENT_TIMESTAMP		DEFAULT_GENERATED	
programs	program_type	enum	NO	program			
score_responses	id	int	NO	NULL	PRI	auto_increment	
score_responses	score_id	int	YES	NULL	MUL		
score_responses	question_id	int	NO	NULL	MUL		
score_responses	response	varchar	YES	NULL			
score_responses	points	int	YES	0			
score_users	score_id	int	NO	NULL	PRI		
score_users	user_id	int	NO	NULL	PRI		
score_users	role	enum	YES	support			
scores	id	int	NO	NULL	PRI	auto_increment	
scores	user_id	int	YES	NULL			
scores	team	varchar	YES	NULL			
scores	program	varchar	NO	NULL			
scores	total_score	int	NO	NULL			
scores	q4	tinyint	YES	0			
scores	q5	tinyint	YES	0			
scores	q6	tinyint	YES	0			
scores	q7	tinyint	YES	0			
scores	q8	tinyint	YES	0			
scores	q9	tinyint	YES	0			
scores	q10	tinyint	YES	0			
scores	q11	tinyint	YES	0			
scores	q12	tinyint	YES	0			
scores	q13	tinyint	YES	0			
scores	q14	tinyint	YES	0			
scores	q15	tinyint	YES	0			
scores	q16	tinyint	YES	0			
scores	q17	tinyint	YES	0			
scores	q18	tinyint	YES	0			
scores	q19	tinyint	YES	0			
scores	q20	tinyint	YES	0			
scores	q21	tinyint	YES	0			
scores	q22	tinyint	YES	0			
scores	q23	tinyint	YES	0			
scores	q24	tinyint	YES	0			
scores	q25	tinyint	YES	0			
scores	q26	tinyint	YES	0			
scores	q27	tinyint	YES	0			
scores	q28	tinyint	YES	0			
scores	q29	tinyint	YES	0			
scores	q30	tinyint	YES	0			
scores	submission_date	datetime	YES	CURRENT_TIMESTAMP		DEFAULT_GENERATED	
scores	form_id	int	YES	NULL			
scores	program_date	date	YES	NULL			
scores	attendance	int	YES	NULL			
scores	adjusted_impact_score	float	YES	0			
scores	scaled_attendance	float	YES	NULL		STORED GENERATED	
scores	team_id	int	YES	NULL	MUL		
scores	location_id	int	YES	NULL			
scoring_options	id	int	NO	NULL	PRI	auto_increment	
scoring_options	question_id	int	NO	NULL	MUL		
scoring_options	option_text	varchar	NO	NULL			
scoring_options	option_points	int	NO	NULL			
scoring_options	points	int	NO	0			
scoring_questions	id	int	NO	NULL	PRI	auto_increment	
scoring_questions	question_text	text	NO	NULL			
scoring_questions	points	int	NO	NULL			
scoring_questions	type	varchar	NO	yesno			
scoring_questions	options	text	YES	NULL			
scoring_questions	sort_order	int	YES	0			
scoring_questions	question_order	int	YES	0			
scoring_questions	question_type	varchar	YES	binary			
teams	id	int	NO	NULL	PRI	auto_increment	
teams	name	varchar	NO	NULL			
temp_merged_scores	program	varchar	NO	NULL			
temp_merged_scores	attendance	int	NO	NULL			
temp_merged_scores	impact_score	int	NO	NULL			
temp_merged_scores	type	varchar	NO	NULL			
temp_merged_scores	month	varchar	NO	NULL			
temp_merged_scores	creator	varchar	NO	NULL			
temp_merged_scores	correct_date	varchar	NO	NULL			
temp_program_dates	program	varchar	YES	NULL			
temp_program_dates	creator	varchar	YES	NULL			
temp_program_dates	correct_date	varchar	YES	NULL			
temp_program_scores	program	varchar	NO	NULL			
temp_program_scores	team	varchar	NO	NULL			
temp_program_scores	attendance	int	NO	NULL			
temp_program_scores	impact_score	int	NO	NULL			
temp_program_scores	user	varchar	NO	NULL			
temp_program_scores	date	date	NO	NULL			
temp_program_scores	user_id	int	YES	NULL			
users	id	int	NO	NULL	PRI	auto_increment	
users	name	varchar	NO	NULL			
users	default_team	int	YES	NULL	MUL		
users	email	varchar	YES	NULL			
