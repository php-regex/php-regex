<?php

/*
 * The pattern a WordPress plugin reads the table of an SQL query with, built
 * from one line per statement: an anchored alternation of some thirty
 * branches, about 700 bytes, whose rewrite the match check proves after some
 * six hundred configurations.
 */

return '/^\\s*(?:(?:EXPLAIN\\s+(?:EXTENDED\\s+)?)?SELECT.*?\\s+FROM|INSERT(?:\\s+LOW_PRIORITY|\\s+DELAYED|\\s+HIGH_PRIORITY)?(?:\\s+IGNORE)?(?:\\s+INTO)?|REPLACE(?:\\s+LOW_PRIORITY|\\s+DELAYED)?(?:\\s+INTO)?|UPDATE(?:\\s+LOW_PRIORITY)?(?:\\s+IGNORE)?|DELETE(?:\\s+LOW_PRIORITY|\\s+QUICK|\\s+IGNORE)*(?:\\s+FROM)?|DESCRIBE|DESC|EXPLAIN|HANDLER|(?:LOCK|UNLOCK)\\s+TABLE(?:S)?|(?:RENAME|OPTIMIZE|BACKUP|RESTORE|CHECK|CHECKSUM|ANALYZE|OPTIMIZE|REPAIR).*\\s+TABLE|TRUNCATE(?:\\s+TABLE)?|CREATE(?:\\s+TEMPORARY)?\\s+TABLE(?:\\s+IF\\s+NOT\\s+EXISTS)?|ALTER(?:\\s+IGNORE)?\\s+TABLE|DROP\\s+TABLE(?:\\s+IF\\s+EXISTS)?|CREATE(?:\\s+\\w+)?\\s+INDEX.*\\s+ON|DROP\\s+INDEX.*\\s+ON|LOAD\\s+DATA.*INFILE.*INTO\\s+TABLE|(?:GRANT|REVOKE).*ON\\s+TABLE|SHOW\\s+(?:.*FROM|.*TABLE))\\s+`?(\\w+)`?/is';
