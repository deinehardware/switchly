<?php

declare(strict_types=1);

/**
 * Owns the process-wide SQLite connection and idempotent schema migrations.
 *
 * The application intentionally has no separate migration command: every web
 * and worker entry point opens the database through this class, ensuring that
 * an existing installation is brought to the current schema before use.
 */
final class Database
{
    private static ?PDO $pdo = null;

    /**
     * Returns the shared SQLite connection configured for concurrent web and
     * worker access through WAL mode and a bounded busy timeout.
     */
    public static function getConnection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $defaultPath = __DIR__ . '/../database.sqlite';
        $dbPath = getenv('SWITCHLY_DB_PATH') ?: $defaultPath;
        $dbDir = dirname($dbPath);

        if (!is_dir($dbDir) && !mkdir($dbDir, 0770, true) && !is_dir($dbDir)) {
            throw new RuntimeException('Datenbankverzeichnis konnte nicht erstellt werden: ' . $dbDir);
        }

        self::$pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$pdo->exec('PRAGMA foreign_keys = ON');
        self::$pdo->exec('PRAGMA journal_mode = WAL');
        self::$pdo->exec('PRAGMA busy_timeout = 5000');
        self::ensureSchema();

        return self::$pdo;
    }

    /** Legt Tabellen und Standardwerte idempotent an und ergänzt fehlende Schemafelder. */
    private static function ensureSchema(): void
    {
        $db = self::$pdo;

        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user',
            two_factor_enabled INTEGER NOT NULL DEFAULT 0,
            two_factor_secret TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $columns = $db->query('PRAGMA table_info(users)')->fetchAll();
        $names = array_column($columns, 'name');
        if (!in_array('role', $names, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
        }
        if (!in_array('two_factor_enabled', $names, true)) {
            $db->exec('ALTER TABLE users ADD COLUMN two_factor_enabled INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('two_factor_secret', $names, true)) {
            $db->exec('ALTER TABLE users ADD COLUMN two_factor_secret TEXT DEFAULT NULL');
        }
        if (!in_array('email', $names, true)) {
            $db->exec('ALTER TABLE users ADD COLUMN email TEXT DEFAULT NULL');
        }
        if (!in_array('email_verified_at', $names, true)) {
            $db->exec('ALTER TABLE users ADD COLUMN email_verified_at DATETIME DEFAULT NULL');
        }
        if (!in_array('email_verification_token', $names, true)) {
            $db->exec('ALTER TABLE users ADD COLUMN email_verification_token TEXT DEFAULT NULL');
        }
        if (!in_array('email_verification_expires_at', $names, true)) {
            $db->exec('ALTER TABLE users ADD COLUMN email_verification_expires_at DATETIME DEFAULT NULL');
        }
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users(email) WHERE email IS NOT NULL');

        $db->exec("CREATE TABLE IF NOT EXISTS sso_identities (
            provider TEXT NOT NULL,
            subject TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            email TEXT DEFAULT NULL,
            display_name TEXT NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (provider, subject),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sso_identities_user ON sso_identities(user_id)');

        $db->exec("CREATE TABLE IF NOT EXISTS oidc_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            enabled INTEGER NOT NULL DEFAULT 0,
            provider_name TEXT NOT NULL DEFAULT 'OpenID Connect',
            public_base_url TEXT NOT NULL DEFAULT '',
            discovery_url TEXT NOT NULL DEFAULT '',
            client_id TEXT NOT NULL DEFAULT '',
            client_secret TEXT NOT NULL DEFAULT '',
            scopes TEXT NOT NULL DEFAULT 'openid email profile',
            client_auth_method TEXT NOT NULL DEFAULT 'post',
            public_client INTEGER NOT NULL DEFAULT 0,
            allow_http INTEGER NOT NULL DEFAULT 0,
            tls_verify INTEGER NOT NULL DEFAULT 1,
            email_claim TEXT NOT NULL DEFAULT 'email',
            username_claim TEXT NOT NULL DEFAULT 'preferred_username',
            name_claim TEXT NOT NULL DEFAULT 'name',
            email_verified_claim TEXT NOT NULL DEFAULT 'email_verified',
            auto_create INTEGER NOT NULL DEFAULT 0,
            allowed_domains TEXT NOT NULL DEFAULT '',
            trust_provider_email INTEGER NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec('INSERT OR IGNORE INTO oidc_settings (id) VALUES (1)');

        // Bootstrap credentials are consumed only when no account exists. An
        // environment change must never reset a password or promote an
        // existing account during a later request.
        $userCount = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($userCount === 0) {
            $adminUser = trim((string)(getenv('SWITCHLY_ADMIN_USER') ?: 'admin'));
            $adminPassword = (string)(getenv('SWITCHLY_ADMIN_PASSWORD') ?: '');
            if ($adminUser === '') {
                throw new RuntimeException('SWITCHLY_ADMIN_USER darf bei einer neuen Installation nicht leer sein.');
            }
            $generatedPassword = false;
            if ($adminPassword === '') {
                $adminPassword = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
                $generatedPassword = true;
            } elseif (strlen($adminPassword) < 12) {
                throw new RuntimeException('Für eine neue Installation muss SWITCHLY_ADMIN_PASSWORD mindestens 12 Zeichen lang sein.');
            }

            $stmt = $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (:username, :hash, 'admin')");
            $stmt->execute([
                'username' => $adminUser,
                'hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
            ]);
            if ($generatedPassword) {
                error_log("\n[Switchly] Initial administrator created\n"
                    . "[Switchly] Username: {$adminUser}\n"
                    . "[Switchly] Generated password: {$adminPassword}\n"
                    . "[Switchly] Store this password securely; it will not be shown again.\n");
            }
        }

        $db->exec("CREATE TABLE IF NOT EXISTS switches (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            host TEXT NOT NULL,
            port INTEGER NOT NULL DEFAULT 80,
            username TEXT NOT NULL DEFAULT 'admin',
            password TEXT NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS switch_cache (
            switch_id TEXT PRIMARY KEY,
            info_json TEXT NOT NULL DEFAULT '{}',
            ports_json TEXT NOT NULL DEFAULT '[]',
            updated_at INTEGER NOT NULL DEFAULT 0,
            is_online INTEGER NOT NULL DEFAULT 0,
            last_error TEXT NOT NULL DEFAULT '',
            FOREIGN KEY (switch_id) REFERENCES switches(id) ON DELETE CASCADE
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS system_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            level TEXT NOT NULL DEFAULT 'INFO',
            event TEXT NOT NULL,
            message TEXT NOT NULL DEFAULT '',
            username TEXT,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $logColumns = array_column($db->query('PRAGMA table_info(system_logs)')->fetchAll(), 'name');
        if (!in_array('switch_id', $logColumns, true)) {
            $db->exec('ALTER TABLE system_logs ADD COLUMN switch_id TEXT DEFAULT NULL');
        }
        if (!in_array('source', $logColumns, true)) {
            $db->exec("ALTER TABLE system_logs ADD COLUMN source TEXT NOT NULL DEFAULT 'cms'");
        }
        $db->exec('CREATE INDEX IF NOT EXISTS idx_system_logs_switch ON system_logs(switch_id, id DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_system_logs_created ON system_logs(created_at)');

        $db->exec("CREATE TABLE IF NOT EXISTS background_job_status (
            job_name TEXT PRIMARY KEY,
            started_at INTEGER NOT NULL DEFAULT 0,
            completed_at INTEGER NOT NULL DEFAULT 0,
            heartbeat_at INTEGER NOT NULL DEFAULT 0,
            success INTEGER NOT NULL DEFAULT 0,
            duration_ms INTEGER NOT NULL DEFAULT 0,
            processed_count INTEGER NOT NULL DEFAULT 0,
            error_count INTEGER NOT NULL DEFAULT 0,
            message TEXT NOT NULL DEFAULT ''
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS permission_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS group_members (
            group_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            PRIMARY KEY (group_id, user_id),
            FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS user_permissions (
            user_id INTEGER NOT NULL,
            permission_code TEXT NOT NULL,
            allowed INTEGER NOT NULL DEFAULT 1,
            PRIMARY KEY (user_id, permission_code),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS group_permissions (
            group_id INTEGER NOT NULL,
            permission_code TEXT NOT NULL,
            allowed INTEGER NOT NULL DEFAULT 1,
            PRIMARY KEY (group_id, permission_code),
            FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS user_switch_access (
            user_id INTEGER NOT NULL,
            switch_id TEXT NOT NULL,
            PRIMARY KEY (user_id, switch_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (switch_id) REFERENCES switches(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS group_switch_access (
            group_id INTEGER NOT NULL,
            switch_id TEXT NOT NULL,
            PRIMARY KEY (group_id, switch_id),
            FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE,
            FOREIGN KEY (switch_id) REFERENCES switches(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS monitoring_samples (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            switch_id TEXT NOT NULL,
            sampled_at INTEGER NOT NULL,
            is_online INTEGER NOT NULL DEFAULT 0,
            ports_up INTEGER NOT NULL DEFAULT 0,
            ports_total INTEGER NOT NULL DEFAULT 0,
            rx_bytes INTEGER NOT NULL DEFAULT 0,
            tx_bytes INTEGER NOT NULL DEFAULT 0,
            errors INTEGER NOT NULL DEFAULT 0,
            response_ms INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (switch_id) REFERENCES switches(id) ON DELETE CASCADE
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_monitoring_switch_time ON monitoring_samples(switch_id, sampled_at DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_monitoring_time ON monitoring_samples(sampled_at)');

        $db->exec("CREATE TABLE IF NOT EXISTS alert_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            enabled INTEGER NOT NULL DEFAULT 0,
            email_enabled INTEGER NOT NULL DEFAULT 0,
            email_recipients TEXT NOT NULL DEFAULT '',
            webhook_enabled INTEGER NOT NULL DEFAULT 0,
            webhook_url TEXT NOT NULL DEFAULT '',
            webhook_secret TEXT NOT NULL DEFAULT '',
            recovery_enabled INTEGER NOT NULL DEFAULT 1,
            failure_threshold INTEGER NOT NULL DEFAULT 2,
            cooldown_minutes INTEGER NOT NULL DEFAULT 30,
            smtp_host TEXT NOT NULL DEFAULT '',
            smtp_port INTEGER NOT NULL DEFAULT 587,
            smtp_secure TEXT NOT NULL DEFAULT 'tls',
            smtp_username TEXT NOT NULL DEFAULT '',
            smtp_password TEXT NOT NULL DEFAULT '',
            smtp_from TEXT NOT NULL DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("INSERT OR IGNORE INTO alert_settings (id) VALUES (1)");
        $alertColumns = array_column($db->query('PRAGMA table_info(alert_settings)')->fetchAll(), 'name');
        foreach ([
            'smtp_host' => "TEXT NOT NULL DEFAULT ''",
            'smtp_port' => 'INTEGER NOT NULL DEFAULT 587',
            'smtp_secure' => "TEXT NOT NULL DEFAULT 'tls'",
            'smtp_username' => "TEXT NOT NULL DEFAULT ''",
            'smtp_password' => "TEXT NOT NULL DEFAULT ''",
            'smtp_from' => "TEXT NOT NULL DEFAULT ''",
        ] as $column => $definition) {
            if (!in_array($column, $alertColumns, true)) {
                $db->exec("ALTER TABLE alert_settings ADD COLUMN {$column} {$definition}");
            }
        }
        $db->exec("CREATE TABLE IF NOT EXISTS alert_states (
            switch_id TEXT PRIMARY KEY,
            last_status INTEGER DEFAULT NULL,
            consecutive_failures INTEGER NOT NULL DEFAULT 0,
            down_alert_sent INTEGER NOT NULL DEFAULT 0,
            last_change_at INTEGER NOT NULL DEFAULT 0,
            last_alert_at INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (switch_id) REFERENCES switches(id) ON DELETE CASCADE
        )");
    }
}
