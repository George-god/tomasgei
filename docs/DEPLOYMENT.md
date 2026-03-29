# Deployment notes

## Checklist

1. **PHP** 8.1+ with extensions: `pdo_mysql`, `mbstring`, `json`, `session`.
2. **Web root** pointed at the `pages/` folder (or project root with URL rewriting to `pages/`—match your host’s layout).
3. **Database** — import once on a new server:
   ```bash
   mysql -u USER -p < database_full.sql
   ```
   Adjust `config/database.php` or bootstrap DB credentials for the environment (consider env vars + a small local override, not committed).
4. **Permissions** — ensure the PHP user can write only where the app needs it (e.g. `cache/` if file cache is used).

## Database variants

- **Full game** (bloodlines, caves, artifacts): `database_full.sql`
- **Core only**: `database_schema.sql`

## PHP

Enable **OPcache** in production (`opcache.enable=1`, reasonable `memory_consumption`, and `validate_timestamps=0` only if you restart or deploy in a way that reloads bytecode).

Realm multipliers are cached per PHP worker (`StatCalculator`). After editing `realms` in the DB, restart PHP-FPM (or workers) if multipliers look stale.
