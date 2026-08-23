-- Copyright (C) 2026 Zachary Melo
-- One matching rule inside a rule set.
-- match_type: 1=prefix 2=suffix 3=regex 4=label 5=type 6=supplier 7=all 8=ref

CREATE TABLE llx_dolicurate_rule(
	rowid         INTEGER      AUTO_INCREMENT PRIMARY KEY,
	entity        INTEGER      NOT NULL DEFAULT 1,
	fk_ruleset    INTEGER      NOT NULL,
	match_type    INTEGER      NOT NULL DEFAULT 1,
	match_value   VARCHAR(255),
	fk_categorie  INTEGER      NOT NULL,
	rang          INTEGER      NOT NULL DEFAULT 0,
	date_creation DATETIME     NOT NULL,
	tms           TIMESTAMP,
	import_key    VARCHAR(14)
) ENGINE=innodb;
