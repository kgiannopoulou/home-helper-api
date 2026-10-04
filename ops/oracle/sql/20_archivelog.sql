-- As SYSDBA in the CDB. Turns on ARCHIVELOG mode, with archived logs in the Fast
-- Recovery Area. Needs a restart through MOUNT: the mode is in the control file.
SET ECHO ON

PROMPT == Before
ARCHIVE LOG LIST
SELECT log_mode FROM v$database;

PROMPT == The Fast Recovery Area: archived logs, RMAN backups and control file autobackups go here
ALTER SYSTEM SET db_recovery_file_dest_size = 10G SCOPE = BOTH;
ALTER SYSTEM SET db_recovery_file_dest = '/opt/oracle/oradata/fra' SCOPE = BOTH;

SHUTDOWN IMMEDIATE
STARTUP MOUNT
ALTER DATABASE ARCHIVELOG;
ALTER DATABASE OPEN;
-- The PDB comes back as it was saved; open it and keep it open after restarts
ALTER PLUGGABLE DATABASE ALL OPEN;
ALTER PLUGGABLE DATABASE freepdb1 SAVE STATE;

PROMPT == After
ARCHIVE LOG LIST
SELECT log_mode FROM v$database;
SHOW PARAMETER db_recovery_file_dest

PROMPT == Close the current online redo log: it's copied (archived) to the FRA
ALTER SYSTEM SWITCH LOGFILE;
ALTER SYSTEM SWITCH LOGFILE;
ALTER SYSTEM ARCHIVE LOG CURRENT;
COLUMN name FORMAT A88
SELECT sequence#, name, ROUND(blocks * block_size / 1024 / 1024, 1) AS mb, first_time, completion_time
FROM v$archived_log ORDER BY sequence#;
SELECT group#, sequence#, status, archived FROM v$log ORDER BY group#;
EXIT
