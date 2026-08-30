/* Read-only. Finds every database on this server that has an
   activity_log table, so you can see where the earlier import put it. */
SELECT TABLE_SCHEMA AS `database`,
       TABLE_NAME   AS `table`,
       CREATE_TIME  AS `created`
  FROM information_schema.TABLES
 WHERE TABLE_NAME = 'activity_log'
 ORDER BY TABLE_SCHEMA;
