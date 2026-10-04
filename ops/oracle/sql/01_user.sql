-- Run as SYSDBA: sqlplus / as sysdba @01_user.sql <hh password>
-- The schema lives in the pluggable database FREEPDB1, in its own tablespace, so the
-- "lost datafile" drill only touches Home Helper's file.
WHENEVER SQLERROR EXIT FAILURE
SET ECHO ON
-- VERIFY OFF: sqlplus would otherwise print the line again with &1 replaced, password and all
SET VERIFY OFF
ALTER SESSION SET CONTAINER = FREEPDB1;

CREATE TABLESPACE hh_data
  DATAFILE '/opt/oracle/oradata/FREE/FREEPDB1/hh_data01.dbf' SIZE 200M
  AUTOEXTEND ON NEXT 100M MAXSIZE 4G;

CREATE USER hh IDENTIFIED BY "&1"
  DEFAULT TABLESPACE hh_data
  QUOTA UNLIMITED ON hh_data;

-- CREATE JOB: the monthly archiving job runs in DBMS_SCHEDULER
GRANT CREATE SESSION, CREATE TABLE, CREATE VIEW, CREATE SEQUENCE, CREATE PROCEDURE, CREATE JOB TO hh;
-- Data Pump writes its dump and log files here
GRANT READ, WRITE ON DIRECTORY data_pump_dir TO hh;

SELECT username, default_tablespace, account_status FROM dba_users WHERE username = 'HH';
EXIT
