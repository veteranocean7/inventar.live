-- Adaugă coloane pentru sistemul cu prefix de tabele
ALTER TABLE utilizatori 
ADD COLUMN tabele_create BOOLEAN DEFAULT 0 AFTER db_name,
ADD COLUMN prefix_tabele VARCHAR(50) DEFAULT NULL AFTER tabele_create;