-- Run as HH in FREEPDB1. Home Helper's MySQL schema in Oracle SQL.
--
-- MySQL               Oracle
-- char(26) ULID   ->  VARCHAR2(26)
-- bigint          ->  NUMBER(19)
-- decimal(10,2)   ->  NUMBER(10,2)
-- varchar(n)      ->  VARCHAR2(n)
-- date            ->  DATE (Oracle's DATE also holds a time; here it's always midnight)
-- timestamp(3)    ->  TIMESTAMP(3)
-- enum(...)       ->  VARCHAR2 + CHECK (Oracle has no ENUM type)
-- boolean         ->  NUMBER(1) CHECK IN (0, 1) (BOOLEAN columns only arrived in 23ai)
--
-- `date` is a reserved word in Oracle, so the day columns are entry_date.
-- The three big tables are partitioned by month (INTERVAL), like the lab asks.
WHENEVER SQLERROR EXIT FAILURE
SET ECHO ON

CREATE TABLE households (
  id          VARCHAR2(26) CONSTRAINT households_pk PRIMARY KEY,
  name        VARCHAR2(100) NOT NULL,
  currency    CHAR(3) DEFAULT 'EUR' NOT NULL,
  created_at  DATE DEFAULT SYSDATE NOT NULL
);

CREATE TABLE users (
  id          NUMBER(19) CONSTRAINT users_pk PRIMARY KEY,
  name        VARCHAR2(255) NOT NULL,
  email       VARCHAR2(255) NOT NULL CONSTRAINT users_email_uk UNIQUE,
  created_at  DATE DEFAULT SYSDATE NOT NULL
);

CREATE TABLE household_users (
  household_id  VARCHAR2(26) NOT NULL CONSTRAINT hu_household_fk REFERENCES households ON DELETE CASCADE,
  user_id       NUMBER(19) NOT NULL CONSTRAINT hu_user_fk REFERENCES users ON DELETE CASCADE,
  role          VARCHAR2(10) DEFAULT 'member' NOT NULL CONSTRAINT hu_role_ck CHECK (role IN ('owner', 'member')),
  CONSTRAINT household_users_pk PRIMARY KEY (household_id, user_id)
);

CREATE TABLE recurring_bills (
  id            VARCHAR2(26) CONSTRAINT recurring_bills_pk PRIMARY KEY,
  household_id  VARCHAR2(26) NOT NULL CONSTRAINT rb_household_fk REFERENCES households ON DELETE CASCADE,
  name          VARCHAR2(100) NOT NULL,
  amount        NUMBER(10,2) NOT NULL CONSTRAINT rb_amount_ck CHECK (amount > 0),
  category      VARCHAR2(20) NOT NULL,
  day_of_month  NUMBER(2) NOT NULL CONSTRAINT rb_day_ck CHECK (day_of_month BETWEEN 1 AND 28)
);

-- Money spent on one day, one partition per month. Oracle adds a partition the first
-- time a row arrives for a new month (INTERVAL); p_start only holds anything older.
CREATE TABLE expenses (
  id                 VARCHAR2(26) NOT NULL,
  household_id       VARCHAR2(26) NOT NULL,
  user_id            NUMBER(19),
  recurring_bill_id  VARCHAR2(26),
  entry_date         DATE NOT NULL,
  amount             NUMBER(10,2) NOT NULL CONSTRAINT expenses_amount_ck CHECK (amount > 0),
  category           VARCHAR2(20) NOT NULL CONSTRAINT expenses_category_ck CHECK (category IN
                       ('groceries', 'household', 'eating_out', 'transport', 'bills', 'health', 'fun', 'shopping', 'other')),
  source             VARCHAR2(10) DEFAULT 'manual' NOT NULL CONSTRAINT expenses_source_ck CHECK (source IN ('manual', 'shopping', 'recurring')),
  note               VARCHAR2(255)
)
PARTITION BY RANGE (entry_date)
INTERVAL (NUMTOYMINTERVAL(1, 'MONTH'))
(PARTITION p_start VALUES LESS THAN (DATE '2023-01-01'));

-- LOCAL: one index partition per table partition, equipartitioned with the table
CREATE INDEX expenses_household_date_lix ON expenses (household_id, entry_date) LOCAL;
-- GLOBAL: one index over every partition, for looking a row up by id
CREATE UNIQUE INDEX expenses_id_gix ON expenses (id) GLOBAL;
ALTER TABLE expenses ADD CONSTRAINT expenses_pk PRIMARY KEY (id) USING INDEX expenses_id_gix;

-- Meals one person logged, by the local day
CREATE TABLE food_entries (
  id            VARCHAR2(26) NOT NULL,
  household_id  VARCHAR2(26) NOT NULL,
  user_id       NUMBER(19) NOT NULL,
  entry_date    DATE NOT NULL,
  eaten_at      TIMESTAMP(3) NOT NULL,
  name          VARCHAR2(100) NOT NULL,
  meal          VARCHAR2(10) NOT NULL CONSTRAINT food_meal_ck CHECK (meal IN ('breakfast', 'lunch', 'dinner', 'snack')),
  kcal          NUMBER(5) NOT NULL CONSTRAINT food_kcal_ck CHECK (kcal <= 5000),
  protein       NUMBER(5,1) DEFAULT 0 NOT NULL,
  fiber         NUMBER(5,1) DEFAULT 0 NOT NULL
)
PARTITION BY RANGE (entry_date)
INTERVAL (NUMTOYMINTERVAL(1, 'MONTH'))
(PARTITION p_start VALUES LESS THAN (DATE '2023-01-01'));

CREATE INDEX food_user_date_lix ON food_entries (user_id, entry_date) LOCAL;
CREATE UNIQUE INDEX food_id_gix ON food_entries (id) GLOBAL;
ALTER TABLE food_entries ADD CONSTRAINT food_entries_pk PRIMARY KEY (id) USING INDEX food_id_gix;

CREATE TABLE rooms (
  id            VARCHAR2(26) CONSTRAINT rooms_pk PRIMARY KEY,
  household_id  VARCHAR2(26) NOT NULL CONSTRAINT rooms_household_fk REFERENCES households ON DELETE CASCADE,
  name          VARCHAR2(100) NOT NULL,
  CONSTRAINT rooms_name_uk UNIQUE (household_id, name)
);

CREATE TABLE chores (
  id            VARCHAR2(26) CONSTRAINT chores_pk PRIMARY KEY,
  household_id  VARCHAR2(26) NOT NULL CONSTRAINT chores_household_fk REFERENCES households ON DELETE CASCADE,
  room_id       VARCHAR2(26) NOT NULL CONSTRAINT chores_room_fk REFERENCES rooms ON DELETE CASCADE,
  name          VARCHAR2(100) NOT NULL,
  every_days    NUMBER(3) NOT NULL CONSTRAINT chores_every_ck CHECK (every_days BETWEEN 1 AND 365),
  minutes       NUMBER(3) NOT NULL CONSTRAINT chores_minutes_ck CHECK (minutes BETWEEN 1 AND 600)
);

-- When a chore was done: a TIMESTAMP partition key works the same way
CREATE TABLE chore_completions (
  id            VARCHAR2(26) NOT NULL,
  household_id  VARCHAR2(26) NOT NULL,
  chore_id      VARCHAR2(26) NOT NULL,
  user_id       NUMBER(19),
  done_at       TIMESTAMP(3) NOT NULL,
  minutes       NUMBER(3) NOT NULL
)
PARTITION BY RANGE (done_at)
INTERVAL (NUMTOYMINTERVAL(1, 'MONTH'))
(PARTITION p_start VALUES LESS THAN (TIMESTAMP '2023-01-01 00:00:00'));

CREATE INDEX completions_chore_done_lix ON chore_completions (chore_id, done_at) LOCAL;
CREATE UNIQUE INDEX completions_id_gix ON chore_completions (id) GLOBAL;
ALTER TABLE chore_completions ADD CONSTRAINT chore_completions_pk PRIMARY KEY (id) USING INDEX completions_id_gix;

CREATE TABLE todos (
  id            VARCHAR2(26) CONSTRAINT todos_pk PRIMARY KEY,
  household_id  VARCHAR2(26) NOT NULL CONSTRAINT todos_household_fk REFERENCES households ON DELETE CASCADE,
  user_id       NUMBER(19),
  title         VARCHAR2(150) NOT NULL,
  due_on        DATE,
  minutes       NUMBER(4),
  done_at       TIMESTAMP(3)
);
CREATE INDEX todos_household_due_ix ON todos (household_id, due_on);

-- Foreign keys on the partitioned tables, added once the parent tables exist
ALTER TABLE expenses ADD CONSTRAINT expenses_household_fk FOREIGN KEY (household_id) REFERENCES households ON DELETE CASCADE;
ALTER TABLE food_entries ADD CONSTRAINT food_household_fk FOREIGN KEY (household_id) REFERENCES households ON DELETE CASCADE;
ALTER TABLE chore_completions ADD CONSTRAINT completions_chore_fk FOREIGN KEY (chore_id) REFERENCES chores ON DELETE CASCADE;

-- The data archive: months moved out of expenses (scripts/archive-month.sh).
-- Same columns, not partitioned, compressed: it's read rarely and only grows.
CREATE TABLE expenses_archive (
  id                 VARCHAR2(26) NOT NULL,
  household_id       VARCHAR2(26) NOT NULL,
  user_id            NUMBER(19),
  recurring_bill_id  VARCHAR2(26),
  entry_date         DATE NOT NULL,
  amount             NUMBER(10,2) NOT NULL,
  category           VARCHAR2(20) NOT NULL,
  source             VARCHAR2(10) NOT NULL,
  note               VARCHAR2(255),
  archived_at        DATE DEFAULT SYSDATE NOT NULL
) COMPRESS;

-- The empty table a partition is swapped with. EXCHANGE needs the same columns,
-- in the same order, with the same types, as the partitioned table.
CREATE TABLE expenses_archive_stage FOR EXCHANGE WITH TABLE expenses;

EXIT
