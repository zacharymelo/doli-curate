-- Copyright (C) 2026 Zachary Melo
-- A named, re-runnable collection of tagging rules.

CREATE TABLE llx_dolicurate_ruleset(
	rowid          INTEGER      AUTO_INCREMENT PRIMARY KEY,
	entity         INTEGER      NOT NULL DEFAULT 1,
	label          VARCHAR(128) NOT NULL,
	description    TEXT,
	only_untagged  INTEGER      NOT NULL DEFAULT 1,
	active         INTEGER      NOT NULL DEFAULT 1,
	date_creation  DATETIME     NOT NULL,
	date_lastrun   DATETIME,
	tms            TIMESTAMP,
	fk_user_creat  INTEGER,
	fk_user_modif  INTEGER,
	import_key     VARCHAR(14)
) ENGINE=innodb;
