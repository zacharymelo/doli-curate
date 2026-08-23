ALTER TABLE llx_dolicurate_log ADD INDEX idx_dolicurate_log_batch (batch_id);
ALTER TABLE llx_dolicurate_log ADD INDEX idx_dolicurate_log_product (fk_product);
ALTER TABLE llx_dolicurate_log ADD INDEX idx_dolicurate_log_categorie (fk_categorie);
ALTER TABLE llx_dolicurate_log ADD INDEX idx_dolicurate_log_undone (undone);
