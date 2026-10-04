-- As HH. The partitions Oracle made on its own while the data was loaded.
SELECT table_name, partitioning_type, interval, partition_count
FROM user_part_tables ORDER BY table_name;

-- expenses: the first and last few, with the row counts from the statistics
SELECT partition_position AS pos, partition_name, high_value, num_rows
FROM user_tab_partitions
WHERE table_name = 'EXPENSES' AND (partition_position <= 4 OR partition_position >= 45)
ORDER BY partition_position;

-- Every partition has its own segment (its own extents on disk)
SELECT COUNT(*) AS segments, ROUND(SUM(bytes) / 1024 / 1024) AS mb
FROM user_segments WHERE segment_name = 'EXPENSES';

-- The indexes: LOCAL is partitioned like the table, GLOBAL is one structure
SELECT i.index_name, i.uniqueness, NVL(p.locality, 'GLOBAL (not partitioned)') AS locality, i.status
FROM user_indexes i
LEFT JOIN user_part_indexes p ON p.index_name = i.index_name
WHERE i.table_name IN ('EXPENSES', 'FOOD_ENTRIES', 'CHORE_COMPLETIONS')
ORDER BY i.table_name, i.index_name;
EXIT
