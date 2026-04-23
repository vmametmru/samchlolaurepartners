import fs from 'fs';
import path from 'path';
import pool from './connection';

async function runMigrations(): Promise<void> {
  const conn = await pool.getConnection();

  try {
    // Ensure migrations table exists
    await conn.execute(`
      CREATE TABLE IF NOT EXISTS db_migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    `);

    const migrationsDir = path.resolve(__dirname, '../../../..', 'database/migrations');
    const files = fs.readdirSync(migrationsDir).filter((f) => f.endsWith('.sql')).sort();

    for (const file of files) {
      const [rows] = await conn.execute<mysql.RowDataPacket[]>(
        'SELECT id FROM db_migrations WHERE filename = ?',
        [file]
      );

      if (rows.length > 0) {
        console.log(`[migrate] Skipping already applied: ${file}`);
        continue;
      }

      const sql = fs.readFileSync(path.join(migrationsDir, file), 'utf-8');
      const statements = sql.split(';').map((s) => s.trim()).filter(Boolean);

      for (const stmt of statements) {
        await conn.execute(stmt);
      }

      await conn.execute('INSERT INTO db_migrations (filename) VALUES (?)', [file]);
      console.log(`[migrate] Applied: ${file}`);
    }

    console.log('[migrate] All migrations applied.');
  } finally {
    conn.release();
    await pool.end();
  }
}

import mysql from 'mysql2/promise';

runMigrations().catch((err) => {
  console.error('[migrate] Error:', err);
  process.exit(1);
});
