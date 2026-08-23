-- Copyright (C) 2026 Zachary Melo
-- Audit trail of every membership change this module makes, grouped into
-- batches so an operation can be reversed as a unit.
-- action: 1=added to category, 2=removed from category
-- source: 1=manual assign, 2=rule set, 3=category merge, 4=undo

CREATE TABLE llx_dolicurate_log(
	rowid         INTEGER      AUTO_INCREMENT PRIMARY KEY,
	entity        INTEGER      NOT NULL DEFAULT 1,
	batch_id      VARCHAR(32)  NOT NULL,
	action        INTEGER      NOT NULL,
	source        INTEGER      NOT NULL DEFAULT 1,
	fk_product    INTEGER      NOT NULL,
	fk_categorie  INTEGER      NOT NULL,
	fk_ruleset    INTEGER,
	undone        INTEGER      NOT NULL DEFAULT 0,
	fk_user       INTEGER,
	date_creation DATETIME     NOT NULL,
	tms           TIMESTAMP,
	import_key    VARCHAR(14)
) ENGINE=innodb;
