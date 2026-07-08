"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const connection_1 = __importDefault(require("./connection"));
async function runMigrations() {
    const conn = await connection_1.default.getConnection();
    try {
        // Ensure migrations table exists
        await conn.execute(`
      CREATE TABLE IF NOT EXISTS db_migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    `);
        const migrationsDir = path_1.default.resolve(__dirname, '../../../..', 'database/migrations');
        const files = fs_1.default.readdirSync(migrationsDir).filter((f) => f.endsWith('.sql')).sort();
        for (const file of files) {
            const [rows] = await conn.execute('SELECT id FROM db_migrations WHERE filename = ?', [file]);
            if (rows.length > 0) {
                console.log(`[migrate] Skipping already applied: ${file}`);
                continue;
            }
            const sql = fs_1.default.readFileSync(path_1.default.join(migrationsDir, file), 'utf-8');
            const statements = sql.split(';').map((s) => s.trim()).filter(Boolean);
            for (const stmt of statements) {
                await conn.execute(stmt);
            }
            await conn.execute('INSERT INTO db_migrations (filename) VALUES (?)', [file]);
            console.log(`[migrate] Applied: ${file}`);
        }
        console.log('[migrate] All migrations applied.');
    }
    finally {
        conn.release();
        await connection_1.default.end();
    }
}
runMigrations().catch((err) => {
    console.error('[migrate] Error:', err);
    process.exit(1);
});
//# sourceMappingURL=migrate.js.map