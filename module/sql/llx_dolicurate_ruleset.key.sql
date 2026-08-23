ALTER TABLE llx_dolicurate_ruleset ADD UNIQUE INDEX uk_dolicurate_ruleset_label (label, entity);
ALTER TABLE llx_dolicurate_ruleset ADD INDEX idx_dolicurate_ruleset_active (active);
