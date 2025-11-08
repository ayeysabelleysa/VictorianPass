# Schema Archive

This folder contains legacy SQL scripts that were superseded by `victorianpass_schema.sql`.

Use `victorianpass_schema.sql` in the project root as the single source of truth to create the entire database (tables, indexes, foreign keys, and optional sample data) in one run via phpMyAdmin.

Archived files:
- `database_setup.sql` — original multi-part setup script.
- `add_receipt_column.sql` — added `receipt_path` to `reservations`; now included in the consolidated schema.
- `add_user_type_column.sql` — added `user_type` to `users`; now included in the consolidated schema.

If you need to reference previous migration steps, you can consult these for historical context.