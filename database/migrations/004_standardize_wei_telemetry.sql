-- FairShare WEI telemetry eligibility and contribution summary metrics.
-- Review and apply manually; this migration has NOT been executed.

CREATE OR REPLACE FUNCTION public.fn_group_wei_summary(p_group_id uuid)
RETURNS TABLE(
    group_id uuid, user_id uuid, student_name text, nim_nip text,
    role_in_group text, valid_event_count bigint,
    member_active_task_count bigint, scored_task_count bigint,
    total_valid_raw_delta numeric, total_filtered_delta numeric,
    wei_percentage numeric, data_status text
)
LANGUAGE sql STABLE SET search_path TO 'public', 'pg_temp'
AS $function$
WITH selected_group AS (
    SELECT g.id FROM public.groups g
    WHERE g.id = p_group_id AND g.group_type = 'team'
),
members AS (
    SELECT gm.group_id, gm.user_id, u.name::text AS student_name,
           u.nim_nip::text AS nim_nip, gm.role_in_group::text AS role_in_group
    FROM public.group_members gm
    JOIN selected_group sg ON sg.id = gm.group_id
    JOIN public.users u ON u.id = gm.user_id
    WHERE u.role = 'mahasiswa'
),
valid_events AS (
    SELECT tl.task_id, tl.group_id, tl.user_id, tl.raw_delta, tl.filtered_delta
    FROM public.telemetry_logs tl
    JOIN selected_group sg ON sg.id = tl.group_id
    JOIN public.tasks t ON t.id = tl.task_id AND t.group_id = tl.group_id
    WHERE tl.task_id IS NOT NULL AND tl.user_id IS NOT NULL
      AND tl.source IN ('gdocs', 'github')
      AND tl.raw_delta >= 0 AND tl.filtered_delta > 0
      AND LOWER(TRIM(COALESCE(tl.metadata->>'validation_status', '')))
          IN ('valid', 'verified', 'terverifikasi')
      AND COALESCE(tl.metadata->>'entry_mode', '') <> 'manual_test'
      AND EXISTS (
          SELECT 1
          FROM public.group_members gm
          JOIN public.users u ON u.id = gm.user_id
          WHERE gm.group_id = tl.group_id
            AND gm.user_id = tl.user_id
            AND u.role = 'mahasiswa'
      )
),
member_task_scores AS (
    SELECT task_id, group_id, user_id, COUNT(*) AS event_count,
           SUM(raw_delta) AS raw_score, SUM(filtered_delta) AS filtered_score
    FROM valid_events GROUP BY task_id, group_id, user_id
),
task_totals AS (
    SELECT task_id, group_id, SUM(filtered_score) AS total_task_score
    FROM member_task_scores GROUP BY task_id, group_id
),
eligible_tasks AS (
    SELECT t.id AS task_id, t.weight, tt.total_task_score
    FROM public.tasks t
    JOIN task_totals tt ON tt.task_id = t.id AND tt.group_id = t.group_id
    WHERE t.group_id = p_group_id AND t.weight > 0 AND tt.total_task_score > 0
),
member_task_matrix AS (
    SELECT m.user_id, m.group_id, et.task_id, et.weight, et.total_task_score,
           COALESCE(ms.filtered_score, 0) AS member_score
    FROM members m CROSS JOIN eligible_tasks et
    LEFT JOIN member_task_scores ms
      ON ms.user_id = m.user_id AND ms.task_id = et.task_id AND ms.group_id = m.group_id
),
wei_by_member AS (
    SELECT user_id, group_id,
           COUNT(*) FILTER (WHERE member_score > 0) AS member_active_task_count,
           COUNT(*) AS scored_task_count,
           ROUND(SUM(weight::numeric * (100.0 * member_score / NULLIF(total_task_score, 0)))
                 / NULLIF(SUM(weight), 0), 4) AS wei_percentage
    FROM member_task_matrix GROUP BY user_id, group_id
),
member_event_totals AS (
    SELECT group_id, user_id, COUNT(*) AS valid_event_count,
           SUM(raw_delta) AS total_valid_raw_delta,
           SUM(filtered_delta) AS total_filtered_delta
    FROM valid_events GROUP BY group_id, user_id
)
SELECT m.group_id, m.user_id, m.student_name, m.nim_nip, m.role_in_group,
       COALESCE(e.valid_event_count, 0), COALESCE(w.member_active_task_count, 0),
       COALESCE(w.scored_task_count, 0), COALESCE(e.total_valid_raw_delta, 0),
       COALESCE(e.total_filtered_delta, 0), w.wei_percentage,
       CASE WHEN w.wei_percentage IS NULL THEN 'Belum cukup data' ELSE 'Tersedia' END
FROM members m
LEFT JOIN wei_by_member w ON w.user_id = m.user_id AND w.group_id = m.group_id
LEFT JOIN member_event_totals e ON e.user_id = m.user_id AND e.group_id = m.group_id
ORDER BY m.student_name;
$function$;

CREATE OR REPLACE VIEW public.v_team_contribution_summary AS
WITH team_members AS (
    SELECT team.id AS team_id, team.name AS team_name,
           class.id AS class_id, class.name AS class_name, gm.user_id,
           u.name AS student_name, u.nim_nip, gm.role_in_group
    FROM public.groups team
    JOIN public.groups class ON class.id = team.parent_group_id AND class.group_type = 'class'
    JOIN public.group_members gm ON gm.group_id = team.id
    JOIN public.users u ON u.id = gm.user_id
    WHERE team.group_type = 'team'
), member_activity AS (
    SELECT tl.group_id, tl.user_id, COUNT(*) AS total_events,
           COUNT(*) FILTER (WHERE tl.source = 'gdocs') AS gdocs_events,
           COUNT(*) FILTER (WHERE tl.source = 'github') AS github_events,
           COALESCE(SUM(tl.raw_delta), 0) AS total_raw_delta,
           COALESCE(SUM(tl.filtered_delta), 0) AS total_filtered_delta,
           COUNT(*) FILTER (WHERE LOWER(COALESCE(tl.metadata->>'validation_status', ''))
               = ANY (ARRAY['valid','verified','terverifikasi'])) AS valid_events,
           COUNT(*) FILTER (WHERE LOWER(COALESCE(tl.metadata->>'validation_status', ''))
               = ANY (ARRAY['pending','unverified','menunggu kalibrasi bobot','menunggu validasi'])) AS pending_events,
           COUNT(*) FILTER (WHERE LOWER(COALESCE(tl.metadata->>'validation_status', ''))
               = ANY (ARRAY['rejected','invalid','ditolak'])) AS rejected_events,
           COUNT(*) FILTER (WHERE tl.metadata->>'entry_mode' = 'manual_test') AS manual_test_events,
           COUNT(*) FILTER (WHERE LOWER(COALESCE(tl.metadata->>'validation_status', ''))
               <> ALL (ARRAY['valid','verified','terverifikasi','pending','unverified',
                             'menunggu kalibrasi bobot','menunggu validasi','rejected','invalid','ditolak'])
               AND COALESCE(tl.metadata->>'entry_mode', '') <> 'manual_test') AS unclassified_events,
           MAX(tl.activity_timestamp) AS last_activity_at,
           COUNT(*) FILTER (WHERE tl.task_id IS NOT NULL AND t.id IS NOT NULL
               AND tl.source IN ('gdocs','github') AND tl.raw_delta >= 0 AND tl.filtered_delta > 0
               AND LOWER(TRIM(COALESCE(tl.metadata->>'validation_status','')))
                   IN ('valid','verified','terverifikasi')
               AND COALESCE(tl.metadata->>'entry_mode','') <> 'manual_test'
               AND EXISTS (SELECT 1 FROM public.group_members gm2
                           JOIN public.users u2 ON u2.id = gm2.user_id
                           WHERE gm2.group_id = tl.group_id AND gm2.user_id = tl.user_id
                             AND u2.role = 'mahasiswa')) AS wei_eligible_events,
           COALESCE(SUM(tl.filtered_delta) FILTER (WHERE tl.task_id IS NOT NULL AND t.id IS NOT NULL
               AND tl.source IN ('gdocs','github') AND tl.raw_delta >= 0 AND tl.filtered_delta > 0
               AND LOWER(TRIM(COALESCE(tl.metadata->>'validation_status','')))
                   IN ('valid','verified','terverifikasi')
               AND COALESCE(tl.metadata->>'entry_mode','') <> 'manual_test'
               AND EXISTS (SELECT 1 FROM public.group_members gm2
                           JOIN public.users u2 ON u2.id = gm2.user_id
                           WHERE gm2.group_id = tl.group_id AND gm2.user_id = tl.user_id
                             AND u2.role = 'mahasiswa')), 0) AS wei_filtered_delta
    FROM public.telemetry_logs tl
    LEFT JOIN public.tasks t ON t.id = tl.task_id AND t.group_id = tl.group_id
    WHERE tl.user_id IS NOT NULL
    GROUP BY tl.group_id, tl.user_id
)
SELECT tm.class_id, tm.class_name, tm.team_id, tm.team_name, tm.user_id,
       tm.student_name, tm.nim_nip, tm.role_in_group,
       COALESCE(ma.total_events, 0) AS total_events,
       COALESCE(ma.gdocs_events, 0) AS gdocs_events,
       COALESCE(ma.github_events, 0) AS github_events,
       COALESCE(ma.total_raw_delta, 0) AS total_raw_delta,
       COALESCE(ma.total_filtered_delta, 0) AS total_filtered_delta,
       COALESCE(ma.valid_events, 0) AS valid_events,
       COALESCE(ma.pending_events, 0) AS pending_events,
       COALESCE(ma.rejected_events, 0) AS rejected_events,
       COALESCE(ma.manual_test_events, 0) AS manual_test_events,
       COALESCE(ma.unclassified_events, 0) AS unclassified_events,
       ma.last_activity_at,
       COALESCE(ma.wei_eligible_events, 0) AS wei_eligible_events,
       COALESCE(ma.wei_filtered_delta, 0) AS wei_filtered_delta
FROM team_members tm
LEFT JOIN member_activity ma ON ma.group_id = tm.team_id AND ma.user_id = tm.user_id;
