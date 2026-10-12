<?php

class Auth
{
    public static function attempt(string $username, string $password): bool
    {
        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT users.*, roles.name AS role_name, roles.slug AS role_slug
            FROM users
            INNER JOIN roles ON roles.id = users.role_id
            WHERE users.username = :username_login OR users.email = :email_login
            LIMIT 1
        ");
        $stmt->execute([
            ':username_login' => $username,
            ':email_login' => $username,
        ]);
        $user = $stmt->fetch();

        if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password'])) {
            return false;
        }

        session_regenerate_id(true);

        $_SESSION['auth_user'] = [
            'id' => $user['id'],
            'role_id' => $user['role_id'],
            'role_name' => $user['role_name'],
            'role_slug' => $user['role_slug'],
            'name' => $user['name'],
            'username' => $user['username'],
            'email' => $user['email'],
            'avatar' => $user['avatar'],
        ];

        unset($_SESSION['auth_permissions']);

        $update = $db->prepare("UPDATE users SET last_login_at = ? WHERE id = ?");
        $update->execute([now_datetime(), $user['id']]);

        self::log('login', 'User berhasil login');
        return true;
    }

    public static function user()
    {
        return $_SESSION['auth_user'] ?? null;
    }

    public static function id()
    {
        return $_SESSION['auth_user']['id'] ?? null;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['auth_user']);
    }

    public static function role(): ?string
    {
        return $_SESSION['auth_user']['role_slug'] ?? null;
    }

    public static function roleId(): ?int
    {
        return isset($_SESSION['auth_user']['role_id']) ? (int) $_SESSION['auth_user']['role_id'] : null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            flash('error', 'Silakan login terlebih dahulu.');
            legacy_redirect('login');
        }
    }

    public static function requireRole(array $roles): void
    {
        self::requireLogin();
        if (!in_array(self::role(), $roles, true)) {
            flash('error', 'Kamu tidak punya akses ke halaman tersebut.');
            legacy_redirect('admin/dashboard.php');
        }
    }

    public static function permissions(): array
    {
        if (!self::check()) {
            return [];
        }

        if (self::role() === 'super_admin') {
            return ['*'];
        }

        if (isset($_SESSION['auth_permissions']) && is_array($_SESSION['auth_permissions'])) {
            return $_SESSION['auth_permissions'];
        }

        $permissions = [];

        try {
            $db = Database::connect();
            self::ensurePermissionTable($db);

            $stmt = $db->prepare("
                SELECT permission_key
                FROM role_permissions
                WHERE role_id = ? AND is_allowed = 1
            ");
            $stmt->execute([self::roleId()]);
            $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            // Fallback aman supaya user lama tetap bisa buka menu dasar
            if (self::role() === 'owner') {
                $permissions = [
                    'dashboard.view', 'pos.access', 'products.view', 'categories.view',
                    'customers.view', 'suppliers.view', 'stock.view', 'transactions.view',
                    'finance.view', 'reports.view', 'returns.view', 'shifts.view',
                    'purchases.view', 'import_products.view', 'settings.view', 'branches.view'
                ];
            } elseif (self::role() === 'cashier') {
                $permissions = [
                    'dashboard.view', 'pos.access', 'transactions.view', 'customers.view'
                ];
            } elseif (self::role() === 'operator_gudang') {
                $permissions = [
                    'dashboard.view', 'warehouse_checks.view', 'warehouse_checks.operator'
                ];
            } elseif (self::role() === 'admin_gudang') {
                $permissions = [
                    'dashboard.view', 'warehouse_checks.view', 'warehouse_checks.admin'
                ];
            } elseif (self::role() === 'quality_control') {
                $permissions = [
                    'dashboard.view', 'warehouse_checks.view', 'warehouse_checks.qc'
                ];
            }
        }

        $_SESSION['auth_permissions'] = $permissions;
        return $permissions;
    }

    public static function can(string $permission): bool
    {
        if (!self::check()) {
            return false;
        }

        if (self::role() === 'super_admin') {
            return true;
        }

        $permissions = self::permissions();

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function requirePermission(string $permission): void
    {
        self::requireLogin();

        if (!self::can($permission)) {
            flash('error', 'Kamu tidak punya hak akses ke halaman tersebut.');
            legacy_redirect('admin/dashboard.php');
        }
    }

    public static function refreshPermissions(): void
    {
        unset($_SESSION['auth_permissions']);
        self::permissions();
    }

    private static function ensurePermissionTable(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS role_permissions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                role_id INT NOT NULL,
                permission_key VARCHAR(120) NOT NULL,
                is_allowed TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY role_permission_unique (role_id, permission_key),
                INDEX idx_role_permissions_role (role_id),
                CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public static function logout(): void
    {
        self::log('logout', 'User logout dari sistem');
        unset($_SESSION['auth_user'], $_SESSION['auth_permissions']);
        session_regenerate_id(true);
    }

    public static function log(string $action, ?string $description = null): void
    {
        try {
            $db = Database::connect();
            $stmt = $db->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                self::id(),
                $action,
                $description,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Throwable $e) {
            // log tidak boleh menghentikan aplikasi
        }
    }
}
